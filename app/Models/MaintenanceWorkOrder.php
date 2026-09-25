<?php

namespace App\Models;

use App\Enums\MaintenanceWorkOrderStatus;
use App\Enums\TaskStatus;
use App\Exceptions\WorkOrderCannotTransitionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 3B Step 4 — the PM planning layer's execution package: one WO
 * groups several Tasks (see Task::maintenanceWorkOrder()). Deliberately a
 * separate concept from the legacy `WorkOrder` model (a pre-Task-Library
 * recurring recipe tied to exactly one equipment+part, still live-wired to
 * TaskService::complete()'s dormant recurrence path, with zero frontend
 * usage today) — see the creating migration's docblock for why that one
 * isn't reused here.
 */
class MaintenanceWorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'line_id',
        'planned_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MaintenanceWorkOrderStatus::class,
            'planned_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'line_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'maintenance_work_order_id');
    }

    /**
     * The only manual transition — everything past this point (in_progress,
     * completed) is derived automatically from what its Tasks actually do
     * (see syncStatusFromTasks()), since "readiness" isn't inferable from
     * task state alone.
     */
    public function markReady(): void
    {
        if ($this->status !== MaintenanceWorkOrderStatus::Planning) {
            throw new WorkOrderCannotTransitionException(
                "WO #{$this->id} tidak lagi berstatus Planning."
            );
        }

        if (! $this->tasks()->exists()) {
            throw new WorkOrderCannotTransitionException(
                "WO #{$this->id} belum punya tugas."
            );
        }

        $this->update(['status' => MaintenanceWorkOrderStatus::Ready]);
    }

    /**
     * Called after any of this WO's Tasks changes status (start/complete/
     * cancel — see TaskService). A WO still in Planning is left alone: only
     * an explicit markReady() moves it out of that state, since a planner
     * may still be assembling it. Once Ready, it becomes InProgress the
     * moment any contained Task starts, and Completed once every
     * non-cancelled Task is Completed (a WO of all-cancelled Tasks is left
     * as-is rather than auto-completing an empty accomplishment).
     */
    public function syncStatusFromTasks(): void
    {
        if ($this->status === MaintenanceWorkOrderStatus::Planning) {
            return;
        }

        $tasks = $this->tasks()->get(['id', 'status']);

        if ($tasks->isEmpty()) {
            return;
        }

        $active = $tasks->reject(fn (Task $task) => $task->status === TaskStatus::Cancelled);

        if ($active->isNotEmpty() && $active->every(fn (Task $task) => $task->status === TaskStatus::Completed)) {
            $this->update(['status' => MaintenanceWorkOrderStatus::Completed]);

            return;
        }

        if (
            $this->status === MaintenanceWorkOrderStatus::Ready
            && $tasks->contains(fn (Task $task) => in_array($task->status, [TaskStatus::InProgress, TaskStatus::Completed], true))
        ) {
            $this->update(['status' => MaintenanceWorkOrderStatus::InProgress]);
        }
    }
}
