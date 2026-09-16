<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\PartInstallation;
use App\Models\Task;
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
 * planned part, which is what makes it show up as a PM task (see
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

            foreach ($library->parts as $libraryPart) {
                TaskPartCheck::create([
                    'task_id' => $task->id,
                    'part_id' => $libraryPart->part_id,
                    'quantity_planned' => $libraryPart->quantity_required,
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
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->get();

        foreach ($libraries as $library) {
            $dueDate = $this->nextRecurringDueDate($library);

            $exists = Task::where('task_library_id', $library->id)
                ->whereDate('due_date', $dueDate->toDateString())
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
