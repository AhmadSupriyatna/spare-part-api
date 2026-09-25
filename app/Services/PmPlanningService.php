<?php

namespace App\Services;

use App\Enums\MaintenanceWorkOrderStatus;
use App\Enums\TaskStatus;
use App\Exceptions\TaskAlreadyInWorkOrderException;
use App\Exceptions\TaskNotEligibleForPlanningException;
use App\Models\MaintenanceWorkOrder;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3B Step 4 — the PM planning layer: Tasks are generated
 * (Task Library / Part Lifetime / ad-hoc) already NEED_PLANNING, one at a
 * time, exactly as before; this service is the only thing that groups them
 * into a MaintenanceWorkOrder. PartReplacementRequest (Failure/Breakdown)
 * never enters here — it's a completely different model/table, never a
 * Task, so it's structurally impossible for it to get pulled into WO
 * grouping.
 */
class PmPlanningService
{
    /**
     * Every ungrouped, not-yet-started Task in the branch — the planning
     * workspace's source list. Only Pending (not InProgress) is eligible:
     * work already underway shouldn't be retroactively pulled into a WO
     * that didn't exist when it started.
     *
     * @return Collection<int, Task>
     */
    public function needingPlanning(int $branchId): Collection
    {
        return Task::query()
            ->where('status', TaskStatus::Pending)
            ->whereNull('maintenance_work_order_id')
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branchId))
            ->with(['equipment.machine.line', 'assignee', 'taskLibrary', 'partChecks.part'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Groups the given Tasks into one new WO. Every Task must: exist,
     * belong to this exact branch, still be Pending, and not already be in
     * a WO — checked explicitly (not just left to a DB constraint) so the
     * planner gets a clear reason, and locked first so two concurrent
     * "create WO" submissions can't both grab the same Task.
     *
     * The grouping dimensions (Branch + Line + planned date/window) aren't
     * enforced as a hard requirement across every selected Task — a
     * planner may deliberately group Tasks spanning more than one Line/date
     * within the same branch. The WO's own denormalized line_id/
     * planned_date are only filled in when every selected Task actually
     * agrees on that value; left null rather than guessed otherwise.
     *
     * @param  list<int>  $taskIds
     */
    public function createFromTasks(int $branchId, array $taskIds, ?int $createdBy): MaintenanceWorkOrder
    {
        if (empty($taskIds)) {
            throw new TaskNotEligibleForPlanningException('Pilih minimal satu tugas.');
        }

        return DB::transaction(function () use ($branchId, $taskIds, $createdBy) {
            $uniqueIds = array_values(array_unique($taskIds));

            $tasks = Task::whereIn('id', $uniqueIds)
                ->with('equipment.machine.line')
                ->lockForUpdate()
                ->get();

            if ($tasks->count() !== count($uniqueIds)) {
                throw new TaskNotEligibleForPlanningException('Salah satu tugas tidak ditemukan.');
            }

            foreach ($tasks as $task) {
                if ($task->maintenance_work_order_id !== null) {
                    throw new TaskAlreadyInWorkOrderException($task->id);
                }

                if ($task->status !== TaskStatus::Pending) {
                    throw new TaskNotEligibleForPlanningException(
                        "Tugas #{$task->id} tidak lagi berstatus Belum Mulai."
                    );
                }

                if ($task->equipment->machine->line->branch_id !== $branchId) {
                    throw new TaskNotEligibleForPlanningException(
                        "Tugas #{$task->id} bukan dari plant yang sama."
                    );
                }
            }

            $lineIds = $tasks->map(fn (Task $task) => $task->equipment->machine->line_id)->unique();
            $dueDates = $tasks->pluck('due_date')->filter()->map(fn ($date) => $date->toDateString())->unique();

            $workOrder = MaintenanceWorkOrder::create([
                'branch_id' => $branchId,
                'line_id' => $lineIds->count() === 1 ? $lineIds->first() : null,
                'planned_date' => $dueDates->count() === 1 ? $dueDates->first() : null,
                'status' => MaintenanceWorkOrderStatus::Planning,
                'created_by' => $createdBy,
            ]);

            Task::whereIn('id', $uniqueIds)->update(['maintenance_work_order_id' => $workOrder->id]);

            return $workOrder->fresh(['tasks.equipment.machine.line', 'line', 'branch', 'creator']);
        });
    }

    /**
     * Step 5 fallback: Tasks that are overdue (the existing due_date/
     * isOverdue() concept — not an invented cutoff) and still ungrouped get
     * swept into a WO automatically, one per (line, due_date) so it stays
     * deterministic. There's no real cron in this app (confirmed: no
     * app/Console commands, no Schedule:: calls, no queued jobs anywhere),
     * so this follows the same established pattern as
     * PmSchedulingService::ensureRecurringSchedules() and
     * PartLifetimeService::autoScheduleAllAtRisk() — called from
     * TaskController::pmSchedule() on every PM Calendar page load, not a
     * background job.
     */
    public function autoGroupOverdueUnplanned(int $branchId): void
    {
        $overdue = Task::query()
            ->where('status', TaskStatus::Pending)
            ->whereNull('maintenance_work_order_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branchId))
            ->with('equipment.machine.line')
            ->get()
            ->groupBy(fn (Task $task) => $task->equipment->machine->line_id.'|'.$task->due_date->toDateString());

        foreach ($overdue as $group) {
            $this->createFromTasks($branchId, $group->pluck('id')->all(), null);
        }
    }
}
