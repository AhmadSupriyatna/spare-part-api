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

    public function taskLibrary(): BelongsTo
    {
        return $this->belongsTo(TaskLibrary::class);
    }

    public function partChecks(): HasMany
    {
        return $this->hasMany(TaskPartCheck::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class);
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

    public function isOverdue(): bool
    {
        if ($this->status !== TaskStatus::Pending && $this->status !== TaskStatus::InProgress) {
            return false;
        }

        // Not isPast() — due_date hydrates as midnight of the due day, so
        // isPast() (which compares against the current instant, not just
        // today's date) would call a task overdue the moment the clock
        // passes midnight on its own due date, hours before the day is
        // actually over. A strictly earlier date is what "overdue" means.
        if ($this->due_date !== null && $this->due_date->lt(today())) {
            return true;
        }

        if ($this->due_runtime_hours !== null) {
            // Phase 3B "Asset Outside Line": no Line means no runtime-hours
            // source (out of scope to solve here) — never considered
            // overdue via this path rather than crashing.
            return ($this->equipment->machine?->line?->runtime_hours ?? 0) >= $this->due_runtime_hours;
        }

        return false;
    }
}
