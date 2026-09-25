<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'maintenance_work_order_id',
        'task_library_id',
        'equipment_id',
        'assigned_to',
        'title',
        'description',
        'status',
        'cause',
        'due_date',
        'due_runtime_hours',
        'started_at',
        'completed_at',
        'completion_notes',
        'part_stock_id',
        'quantity_used',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'due_runtime_hours' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'quantity_used' => 'integer',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Phase 3B Step 4's planning-layer WO — deliberately a different
     * relation/column (maintenance_work_order_id) from the legacy
     * workOrder() above; see MaintenanceWorkOrder's own docblock.
     */
    public function maintenanceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class);
    }

    public function taskLibrary(): BelongsTo
    {
        return $this->belongsTo(TaskLibrary::class);
    }

    public function partChecks(): HasMany
    {
        return $this->hasMany(TaskPartCheck::class);
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(TaskReschedule::class)->latest();
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }

    /**
     * NEED_PLANNING / IN_WO / COMPLETED (Phase 3B Step 4) — derived, not a
     * persisted column: a Completed/Cancelled task reports its real status
     * verbatim (both are terminal and no longer relevant to planning
     * either way), otherwise it's IN_WO once grouped into a
     * MaintenanceWorkOrder or NEED_PLANNING while still ungrouped.
     */
    public function planningState(): string
    {
        if (in_array($this->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)) {
            return $this->status->value;
        }

        return $this->maintenance_work_order_id !== null ? 'in_wo' : 'need_planning';
    }

    public function isOverdue(): bool
    {
        if ($this->status !== TaskStatus::Pending && $this->status !== TaskStatus::InProgress) {
            return false;
        }

        if ($this->due_date !== null && $this->due_date->isPast()) {
            return true;
        }

        if ($this->due_runtime_hours !== null) {
            return $this->equipment->machine->line->runtime_hours >= $this->due_runtime_hours;
        }

        return false;
    }
}
