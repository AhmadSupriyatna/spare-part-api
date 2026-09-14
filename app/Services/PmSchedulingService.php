<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\PartInstallation;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use Illuminate\Support\Facades\DB;

/**
 * Turns a PM "candidate" — either a Task Library recipe or a part flagged
 * by PartLifetimeService as nearly worn out — into an actual dated Task
 * once someone picks a date for it on the PM calendar. Deliberately manual,
 * one at a time, not auto-recurring like the older WorkOrder interval-based
 * scheduling. Either path leaves the resulting Task with one TaskPartCheck
 * row per planned part, which is what makes it show up as a PM task
 * (see TaskController::pmSchedule()) and completes via the checklist flow.
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
                'description' => 'Dijadwalkan karena sisa umur pakai part ini sudah di bawah 10%.',
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
}
