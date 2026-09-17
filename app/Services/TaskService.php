<?php

namespace App\Services;

use App\Enums\ScheduleType;
use App\Enums\TaskStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\PartStockNotFoundException;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly PartLifecycleService $lifecycle,
    ) {}

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
     *
     * $checks is used instead, exclusively, when the task came from a Task
     * Library: one entry per planned part — `{part_id, is_replaced,
     * quantity_used?, reason?, part_unit_id?, old_installation_id?}` — see
     * completeChecklist().
     *
     * @param  array<int, array{id: int, part_id: int, is_replaced: bool, quantity_used?: ?int, reason?: ?string, part_unit_id?: ?int, old_installation_id?: ?int}>|null  $checks
     *
     * @throws PartStockNotFoundException if a checklist part is marked replaced
     *                                    with a brand-new unit but has no stock record in the equipment's branch.
     * @throws AmbiguousInstallationException if the equipment has more than one
     *                                        active installation of a replaced part and old_installation_id wasn't given.
     */
    public function complete(
        Task $task,
        ?User $user,
        ?string $notes = null,
        ?int $partStockId = null,
        ?int $quantityUsed = null,
        ?array $checks = null,
    ): Task {
        return DB::transaction(function () use ($task, $user, $notes, $partStockId, $quantityUsed, $checks) {
            // A checklist (task_part_checks rows) is what actually marks a
            // task as "PM flow" now — it's created whether the task came
            // from a Task Library recipe or a lifetime-worn-part flag, so
            // checking for it (not task_library_id specifically) covers both.
            if ($task->partChecks()->exists()) {
                $this->completeChecklist($task, $user, $checks ?? []);
            } else {
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

    /**
     * @param  array<int, array{id: int, part_id: int, is_replaced: bool, quantity_used?: ?int, reason?: ?string, part_unit_id?: ?int, old_installation_id?: ?int}>  $checks
     */
    private function completeChecklist(Task $task, ?User $user, array $checks): void
    {
        $branchId = $task->equipment->machine->line->branch_id;

        // Resolve "which old installation is this closing" for every
        // replaced check up front, before any of this batch's own
        // remove()/install() calls run. Doing it lazily inside the loop
        // below was a real bug: a brand-new installation created for an
        // earlier row of the same part (e.g. 3 checklist rows all planning
        // fresh units of the same bearing) would itself look like "the"
        // one pre-existing active installation to the next row, so that
        // row would wrongly close its own sibling instead of creating an
        // independent new one. Resolving against a stable snapshot first —
        // and excluding whatever's already been claimed within this same
        // batch — keeps each row's resolution about what genuinely existed
        // before this completion request started.
        $oldInstallationByCheckId = [];
        $claimedInstallationIds = [];
        foreach ($checks as $check) {
            if (empty($check['is_replaced'])) {
                continue;
            }

            $old = $this->lifecycle->resolveActiveInstallation(
                $task->equipment,
                $check['part_id'],
                $check['old_installation_id'] ?? null,
                $claimedInstallationIds,
            );

            $oldInstallationByCheckId[$check['id']] = $old;

            if ($old) {
                $claimedInstallationIds[] = $old->id;
            }
        }

        foreach ($checks as $check) {
            // Identified by the check row's own id, not part_id — a task
            // can now have several rows for the same part (one per address/
            // unit, see PmSchedulingService::schedule()), so part_id alone
            // is no longer unique within one task's checklist.
            $partCheck = TaskPartCheck::where('task_id', $task->id)
                ->where('id', $check['id'])
                ->firstOrFail();

            $isReplaced = (bool) $check['is_replaced'];
            $quantityUsedForPart = $isReplaced ? ($check['quantity_used'] ?? $partCheck->quantity_planned) : null;
            $reason = $isReplaced ? null : ($check['reason'] ?? null);
            $installation = null;

            if ($isReplaced) {
                // One scan = one PartUnit/PartInstallation, even if
                // quantity_planned is >1 — the checklist still tracks how
                // many pieces were consumed for stock purposes, but the QR
                // scan flow only identifies a single physical unit per check.
                $isNewUnit = empty($check['part_unit_id']);

                if ($isNewUnit) {
                    $partStock = PartStock::where('part_id', $check['part_id'])
                        ->where('branch_id', $branchId)
                        ->first();

                    if (! $partStock) {
                        throw new PartStockNotFoundException($check['part_id'], $branchId);
                    }

                    $this->stockMovements->record(
                        partStock: $partStock,
                        type: StockLedger::TYPE_ISSUE,
                        quantityChange: -$quantityUsedForPart,
                        user: $user,
                        notes: "Checklist WO PM #{$task->id}: {$task->title}",
                        reference: $task,
                    );
                }

                $oldInstallation = $oldInstallationByCheckId[$check['id']] ?? null;

                if ($oldInstallation) {
                    $this->lifecycle->remove($oldInstallation);
                }

                $installation = $this->lifecycle->install($task->equipment, [
                    'part_id' => $check['part_id'],
                    'part_unit_id' => $check['part_unit_id'] ?? null,
                    'replacing_installation_id' => $oldInstallation?->id,
                ], $user);
            }

            $partCheck->update([
                'is_replaced' => $isReplaced,
                'quantity_used' => $quantityUsedForPart,
                'reason' => $reason,
                'part_installation_id' => $installation?->id,
            ]);
        }
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
     * by whoever picks it up. Quantity is left for the technician to confirm
     * at completion time (see TaskController::complete()'s part_stock_id/
     * quantity_used override).
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

        if ($workOrder->part_id) {
            $branchId = $workOrder->equipment->machine->line->branch_id;

            $partStockId = PartStock::where('part_id', $workOrder->part_id)
                ->where('branch_id', $branchId)
                ->value('id');
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
            'quantity_used' => null,
        ]);
    }
}
