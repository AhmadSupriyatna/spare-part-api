<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartInstallation extends Model
{
    protected $fillable = [
        'equipment_id',
        'part_id',
        'part_unit_id',
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

    public function partUnit(): BelongsTo
    {
        return $this->belongsTo(PartUnit::class);
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
     * A recurring work order (if any) covering this equipment+part pair —
     * kept for the WorkOrder-driven auto-recurring PM flow, but no longer
     * what remaining lifetime is computed from (see percentUsed()).
     */
    public function relevantWorkOrder(): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('equipment_id', $this->equipment_id)
            ->where('part_id', $this->part_id)
            ->whereIn('schedule_type', ['calendar', 'runtime'])
            ->first();
    }

    /**
     * How worn this installation's unit is overall, as a percentage of the
     * part's estimated lifetime — delegates to PartUnit, which accumulates
     * runtime hours across every installation cycle the unit has had, not
     * just this one. Null if the unit has no estimated_lifetime_hours to
     * compare against.
     */
    public function percentUsed(): ?int
    {
        return $this->partUnit?->percentUsed();
    }
}
