<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\PartInstallation;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a PM "candidate" — either a Task Library recipe or a part flagged
 * by PartLifetimeService as nearly worn out — into an actual dated Task.
 * A Task Library with a Weekly/Monthly calendar schedule_type is
 * auto-recurring (see ensureRecurringSchedules()); everything else
 * (one-shot picks, Gauge/runtime-based libraries, Part Lifetime) still
 * goes through schedule()/scheduleFromLifetime() directly, one at a time.
 * Either path leaves the resulting Task with one TaskPartCheck row per
 * planned *unit* (see below — a library part planned in quantity 3 becomes
 * 3 separate rows, all quantity_planned=1, not one row of quantity_planned
 * 3), which is what makes it show up as a PM task (see
 * TaskController::pmSchedule()) and completes via the checklist flow.
 */
class PmSchedulingService
{
    public function schedule(TaskLibrary $library, string $dueDate, ?int $assignedTo): Task
    {
        return DB::transaction(function () use ($library, $dueDate, $assignedTo) {
            $task = Task::create([
                'task_library_id' => $library->id,
                'equipment_id' => $library->equipment_id,
                'assigned_to' => $assignedTo,
                'title' => $library->title,
                'description' => $library->description,
                'status' => TaskStatus::Pending,
                'due_date' => $dueDate,
            ]);

            // One row per planned unit, not one row per part — "satu baris
            // satu alamat": a checklist row is what the technician picks an
            // A/B/C installation for when replacing it (see
            // TaskService::resolveActiveInstallation()), so a part planned
            // in quantity 3 needs 3 independently-completable rows, each
            // able to point at a different installation.
            foreach ($library->parts as $libraryPart) {
                for ($i = 0; $i < $libraryPart->quantity_required; $i++) {
                    TaskPartCheck::create([
                        'task_id' => $task->id,
                        'part_id' => $libraryPart->part_id,
                        'quantity_planned' => 1,
                    ]);
                }
            }

            // Condition-check items (Kondisi Baik/Tidak), snapshotted the
            // same way — independent of the part checklist above.
            foreach ($library->checklistItems as $libraryItem) {
                TaskChecklistItem::create([
                    'task_id' => $task->id,
                    'task_library_checklist_item_id' => $libraryItem->id,
                    'description' => $libraryItem->description,
                ]);
            }

            return $task;
        });
    }

    /**
     * Schedule the replacement of one specific installed part flagged as
     * nearly worn out — a single-part checklist, since it's always exactly
     * the one part sitting in that installation row.
     */
    public function scheduleFromLifetime(PartInstallation $installation, string $dueDate, ?int $assignedTo): Task
    {
        return DB::transaction(function () use ($installation, $dueDate, $assignedTo) {
            $task = Task::create([
                'equipment_id' => $installation->equipment_id,
                'assigned_to' => $assignedTo,
                'title' => "Ganti {$installation->part->name} (Umur Pakai Habis)",
                'description' => 'Dijadwalkan karena sisa umur pakai part ini sudah di bawah 20%.',
                'status' => TaskStatus::Pending,
                'due_date' => $dueDate,
            ]);

            TaskPartCheck::create([
                'task_id' => $task->id,
                'part_id' => $installation->part_id,
                'quantity_planned' => 1,
            ]);

            return $task;
        });
    }

    /**
     * Schedule a one-off Task with no Task Library recipe behind it —
     * currently only reached from MaintenanceDecisionService's MONITOR/
     * SCHEDULE_MAINTENANCE ad-hoc follow-up path (see Phase 2 Step 4). One
     * TaskPartCheck row when a part is given, same quantity_planned=1 shape
     * as every other single-part checklist row this service creates (see
     * scheduleFromLifetime()).
     */
    public function scheduleAdHoc(
        Equipment $equipment,
        string $title,
        string $dueDate,
        ?int $partId,
        ?string $description,
        ?int $assignedTo,
    ): Task {
        return DB::transaction(function () use ($equipment, $title, $dueDate, $partId, $description, $assignedTo) {
            $task = Task::create([
                'equipment_id' => $equipment->id,
                'assigned_to' => $assignedTo,
                'title' => $title,
                'description' => $description,
                'status' => TaskStatus::Pending,
                'due_date' => $dueDate,
            ]);

            if ($partId) {
                TaskPartCheck::create([
                    'task_id' => $task->id,
                    'part_id' => $partId,
                    'quantity_planned' => 1,
                ]);
            }

            return $task;
        });
    }

    /**
     * Auto-creates the current occurrence for every active, Weekly
     * (interval_days=7) or Monthly (interval_days=30) calendar-type Task
     * Library in this branch, if one doesn't already exist for that period
     * — called every time the PM Schedule page loads (TaskController::
     * pmSchedule()), which is what makes this "automatic" without a real
     * cron/scheduler running in local dev. Weekly always lands on the
     * upcoming Monday; Monthly on the first Monday of the current month
     * (or next month's, once this month's has passed).
     */
    public function ensureRecurringSchedules(Branch $branch): void
    {
        $libraries = TaskLibrary::query()
            ->where('is_active', true)
            ->where('schedule_type', 'calendar')
            ->whereIn('interval_days', [7, 30])
            // Phase 3B "Asset Outside Line": a Task Library recipe on an
            // outside-line asset has no equipment.machine.line to match
            // through, so it also matches on the asset's own branch_id.
            ->where(function ($q) use ($branch) {
                $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branch->id))
                    ->orWhereHas('equipment', fn ($q2) => $q2->where('branch_id', $branch->id));
            })
            ->get();

        foreach ($libraries as $library) {
            $dueDate = $this->nextRecurringDueDate($library);

            // Bug fix: a task generated for this occurrence but since dragged
            // to a different date (TaskController::reschedule()) must still
            // count as "this period already has a task" — matching only on
            // the CURRENT due_date meant the moved task no longer sat on its
            // natural date, so the next page load saw the period as
            // unscheduled and created a duplicate right back on that date,
            // while the dragged one kept sitting on the new one. Every
            // reschedule leaves a TaskReschedule row (previous_due_date =
            // whatever the date was right before that specific move), so the
            // task's very first reschedule row still points back to the
            // natural occurrence date even after several subsequent moves.
            $exists = Task::where('task_library_id', $library->id)
                ->where(function ($q) use ($dueDate) {
                    $q->whereDate('due_date', $dueDate->toDateString())
                        ->orWhereHas(
                            'reschedules',
                            fn ($q2) => $q2->whereDate('previous_due_date', $dueDate->toDateString())
                        );
                })
                ->exists();

            if (! $exists) {
                $this->schedule($library, $dueDate->toDateString(), null);
            }
        }
    }

    private function nextRecurringDueDate(TaskLibrary $library): Carbon
    {
        $today = now()->startOfDay();

        if ($library->interval_days === 7) {
            return $this->firstMondayOnOrAfter($today);
        }

        // Monthly: first Monday of this month, or of next month if that's already passed.
        $candidate = $this->firstMondayOnOrAfter($today->copy()->startOfMonth());

        return $candidate->lt($today) ? $this->firstMondayOnOrAfter($today->copy()->addMonthNoOverflow()->startOfMonth()) : $candidate;
    }

    private function firstMondayOnOrAfter(Carbon $date): Carbon
    {
        $date = $date->copy()->startOfDay();

        return $date->isMonday() ? $date : $date->next(Carbon::MONDAY);
    }
}
