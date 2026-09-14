<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use Illuminate\Support\Facades\DB;

/**
 * Turns a Task Library "recipe" into an actual dated Task once someone picks
 * a date for it on the PM calendar — deliberately manual, one at a time, not
 * auto-recurring like the older WorkOrder interval-based scheduling.
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
}
