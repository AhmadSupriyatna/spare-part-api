<?php

namespace App\Services;

use App\Enums\ScheduleType;
use App\Enums\TaskStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\ChecklistPartMismatchException;
use App\Exceptions\PartStockBranchMismatchException;
use App\Exceptions\PartStockNotFoundException;
use App\Exceptions\TaskAlreadyCompletedException;
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
        private readonly MaintenanceFindingService $findings,
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
     * quantity_used?, reason?, part_unit_id?, old_installation_id?, finding?}`
     * — see completeChecklist(). `finding` is entirely optional and
     * independent of is_replaced (see completeChecklist()'s own docblock).
     *
     * @param  array<int, array{id: int, part_id: int, is_replaced: bool, quantity_used?: ?int, reason?: ?string, part_unit_id?: ?int, old_installation_id?: ?int, finding?: ?array{description: string, result: string, severity?: ?string, recommendation?: ?string}}>|null  $checks
     *
     * @throws TaskAlreadyCompletedException if the task is already Completed —
     *                                       checked first, before any stock/lifecycle mutation, so a second
     *                                       completion attempt (e.g. a double-submit or a stale retry) can never
     *                                       repeat the replacement.
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
            // Locked so a concurrent completion request for the same task
            // can't both read "not yet Completed" before either commits —
            // the second request blocks on this row until the first
            // transaction finishes, then re-reads the now-Completed status
            // and is rejected below, the same pattern already used by
            // PartReplacementService::approve() for the same class of
            // problem (a request being reviewed twice at once).
            $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TaskStatus::Completed) {
                throw new TaskAlreadyCompletedException($locked->id);
            }

            // A checklist (task_part_checks rows) is what actually marks a
            // task as "PM flow" now — it's created whether the task came
            // from a Task Library recipe or a lifetime-worn-part flag, so
            // checking for it (not task_library_id specifically) covers both.
            if ($locked->partChecks()->exists()) {
                $this->completeChecklist($locked, $user, $checks ?? []);
            } else {
                if ($partStockId) {
                    $locked->update([
                        'part_stock_id' => $partStockId,
                        'quantity_used' => $quantityUsed,
                    ]);
                    // Force the relation to re-resolve against the new foreign key
                    // instead of serving whatever was cached on it before.
                    $locked->unsetRelation('partStock');
                }

                if ($locked->part_stock_id && $locked->quantity_used) {
                    $partStock = $locked->partStock;
                    $branchId = $locked->equipment->resolvedBranchId();

                    // Never trust that a submitted (or pre-filled) part_stock_id
                    // actually belongs to this task's own equipment's plant —
                    // checked here, before StockMovementService is ever called,
                    // so a mismatched stock can't be issued against the wrong
                    // branch's ledger.
                    if ($partStock->branch_id !== $branchId) {
                        throw new PartStockBranchMismatchException($partStock->id, $branchId);
                    }

                    $this->stockMovements->record(
                        partStock: $partStock,
                        type: StockLedger::TYPE_ISSUE,
                        quantityChange: -$locked->quantity_used,
                        user: $user,
                        notes: "Dipakai untuk tugas #{$locked->id}: {$locked->title}",
                        reference: $locked,
                    );
                }
            }

            $locked->update([
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'completion_notes' => $notes,
            ]);

            if ($locked->work_order_id) {
                $workOrder = $locked->workOrder;
                if ($workOrder && $workOrder->is_active && $workOrder->schedule_type !== ScheduleType::Unscheduled) {
                    $this->generateNextTask($workOrder, $locked);
                }
            }

            return $locked;
        });
    }

    /**
     * @param  array<int, array{id: int, part_id: int, is_replaced: bool, quantity_used?: ?int, reason?: ?string, part_unit_id?: ?int, old_installation_id?: ?int, finding?: ?array{description: string, result: string, severity?: ?string, recommendation?: ?string}}>  $checks
     */
    private function completeChecklist(Task $task, ?User $user, array $checks): void
    {
        $branchId = $task->equipment->resolvedBranchId();

        // The client says which part a checklist row is for, but that's
        // never trusted at face value — it must match what the actual
        // TaskPartCheck row already has on file, checked before anything
        // else in this method runs (including the old-installation
        // resolution pass below, which also keys off $check['part_id']).
        // Otherwise a spoofed part_id could make this method act on a
        // completely different part than what was actually scheduled.
        $taskPartChecks = TaskPartCheck::where('task_id', $task->id)
            ->whereIn('id', collect($checks)->pluck('id'))
            ->get()
            ->keyBy('id');

        foreach ($checks as $check) {
            $partCheck = $taskPartChecks->get($check['id']);

            if (! $partCheck || $partCheck->part_id !== $check['part_id']) {
                throw new ChecklistPartMismatchException($check['id']);
            }
        }

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
            $oldInstallation = null;

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
                    $this->lifecycle->remove($oldInstallation, $user);
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
                'old_installation_id' => $oldInstallation?->id,
            ]);

            // Entirely optional and independent of is_replaced — a Finding
            // can document *why* a part was left alone just as validly as
            // it can document what was observed before a replacement. Never
            // required, never blocks completion: this only runs the same
            // create-a-row-and-move-on path, inside the transaction already
            // wrapping this whole method, so a failure here rolls back the
            // checklist update (and any stock/install/remove) alongside it.
            if (! empty($check['finding'])) {
                $this->findings->createFromTaskPartCheck($partCheck, $task->equipment_id, $check['finding'], $user);
            }
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
            // Phase 3B "Asset Outside Line": no Line means no runtime-hours
            // source at all (out of scope to solve here) — falls back to 0
            // rather than crashing, same as everywhere else this reads
            // line runtime hours.
            $dueRuntimeHours = ($workOrder->equipment->machine?->line?->runtime_hours ?? 0) + $workOrder->interval_hours;
        }

        $partStockId = null;

        if ($workOrder->part_id) {
            $branchId = $workOrder->equipment->resolvedBranchId();

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
