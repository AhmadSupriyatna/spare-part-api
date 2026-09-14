<?php

namespace App\Services;

use App\Enums\ScheduleType;
use App\Enums\TaskStatus;
use App\Models\EquipmentPart;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(private readonly StockMovementService $stockMovements) {}

    public function start(Task $task): Task
    {
        $task->update([
            'status' => TaskStatus::InProgress,
            'started_at' => now(),
        ]);

        return $task;
    }

    /**
     * Complete a task. If it references a part_stock + quantity_used, records
     * the usage as a stock "issue" through the same ledger path as everything
     * else, then generates the work order's next occurrence (if any).
     *
     * $partStockId/$quantityUsed let the technician confirm or correct what was
     * actually used at completion time, overriding whatever the task was
     * pre-filled with (e.g. from the work order's planned part).
     */
    public function complete(
        Task $task,
        ?User $user,
        ?string $notes = null,
        ?int $partStockId = null,
        ?int $quantityUsed = null,
    ): Task {
        return DB::transaction(function () use ($task, $user, $notes, $partStockId, $quantityUsed) {
            if ($partStockId) {
                $task->update([
                    'part_stock_id' => $partStockId,
                    'quantity_used' => $quantityUsed,
                ]);
                // Force the relation to re-resolve against the new foreign key
                // instead of serving whatever was cached on it before.
                $task->unsetRelation('partStock');
            }

            if ($task->part_stock_id && $task->quantity_used) {
                $this->stockMovements->record(
                    partStock: $task->partStock,
                    type: StockLedger::TYPE_ISSUE,
                    quantityChange: -$task->quantity_used,
                    user: $user,
                    notes: "Dipakai untuk tugas #{$task->id}: {$task->title}",
                    reference: $task,
                );
            }

            $task->update([
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'completion_notes' => $notes,
            ]);

            if ($task->work_order_id) {
                $workOrder = $task->workOrder;
                if ($workOrder && $workOrder->is_active && $workOrder->schedule_type !== ScheduleType::Unscheduled) {
                    $this->generateNextTask($workOrder, $task);
                }
            }

            return $task;
        });
    }

    public function cancel(Task $task, ?string $notes = null): Task
    {
        $task->update([
            'status' => TaskStatus::Cancelled,
            'completion_notes' => $notes,
        ]);

        return $task;
    }

    /**
     * Create the next task instance for a recurring work order, due either
     * a fixed number of days after the previous completion (calendar-based)
     * or once the equipment reaches a target runtime (runtime-based).
     *
     * If the work order names a part to replace, resolve it to that
     * equipment's branch stock and pre-fill the task's part_stock_id, so the
     * "what part does this PM use" knowledge set when the work order was
     * defined carries through to the actual task instead of being re-entered
     * by whoever picks it up. The quantity defaults from the equipment's BOM
     * (equipment_parts.quantity_required) when there's a matching entry.
     */
    public function generateNextTask(WorkOrder $workOrder, ?Task $previousTask = null): Task
    {
        $dueDate = null;
        $dueRuntimeHours = null;

        if ($workOrder->schedule_type === ScheduleType::Calendar && $workOrder->interval_days) {
            $base = $previousTask?->completed_at ?? now();
            $dueDate = $base->copy()->addDays($workOrder->interval_days);
        }

        if ($workOrder->schedule_type === ScheduleType::Runtime && $workOrder->interval_hours) {
            $dueRuntimeHours = $workOrder->equipment->machine->line->runtime_hours + $workOrder->interval_hours;
        }

        $partStockId = null;
        $quantityUsed = null;

        if ($workOrder->part_id) {
            $branchId = $workOrder->equipment->machine->line->branch_id;

            $partStockId = PartStock::where('part_id', $workOrder->part_id)
                ->where('branch_id', $branchId)
                ->value('id');

            if ($partStockId) {
                $quantityUsed = EquipmentPart::where('equipment_id', $workOrder->equipment_id)
                    ->where('part_id', $workOrder->part_id)
                    ->value('quantity_required');
            }
        }

        return Task::create([
            'work_order_id' => $workOrder->id,
            'equipment_id' => $workOrder->equipment_id,
            'title' => $workOrder->title,
            'description' => $workOrder->description,
            'status' => TaskStatus::Pending,
            'due_date' => $dueDate,
            'due_runtime_hours' => $dueRuntimeHours,
            'part_stock_id' => $partStockId,
            'quantity_used' => $quantityUsed,
        ]);
    }
}
