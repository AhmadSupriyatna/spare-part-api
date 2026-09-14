<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartInstallation extends Model
{
    protected $fillable = [
        'equipment_id',
        'part_id',
        'installed_at',
        'installed_at_runtime_hours',
        'removed_at',
        'removed_at_runtime_hours',
        'installed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'installed_at_runtime_hours' => 'integer',
            'removed_at' => 'datetime',
            'removed_at_runtime_hours' => 'integer',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    public function ageInDays(): int
    {
        $end = $this->removed_at ?? now();

        return (int) $this->installed_at->diffInDays($end);
    }

    public function ageInRuntimeHours(): ?int
    {
        if ($this->installed_at_runtime_hours === null) {
            return null;
        }

        $currentHours = $this->removed_at_runtime_hours
            ?? $this->equipment->machine->line->runtime_hours;

        return max(0, $currentHours - $this->installed_at_runtime_hours);
    }

    /**
     * The recurring work order (if any) that governs this part's expected
     * lifespan on this equipment, used to compute a "% worn" indicator.
     */
    public function relevantWorkOrder(): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('equipment_id', $this->equipment_id)
            ->where('part_id', $this->part_id)
            ->whereIn('schedule_type', ['calendar', 'runtime'])
            ->first();
    }
}
