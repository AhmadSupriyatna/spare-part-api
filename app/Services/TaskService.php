<?php

namespace App\Services;

use App\Enums\ScheduleType;
use App\Enums\TaskStatus;
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
     */
    public function complete(Task $task, ?User $user, ?string $notes = null): Task
    {
        return DB::transaction(function () use ($task, $user, $notes) {
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

        return Task::create([
            'work_order_id' => $workOrder->id,
            'equipment_id' => $workOrder->equipment_id,
            'title' => $workOrder->title,
            'description' => $workOrder->description,
            'status' => TaskStatus::Pending,
            'due_date' => $dueDate,
            'due_runtime_hours' => $dueRuntimeHours,
        ]);
    }
}
