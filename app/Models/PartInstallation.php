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

    /**
     * How worn this installation is, as a percentage of its governing work
     * order's expected calendar or runtime-hour interval — null if there's
     * no work order to compare against (nothing defines "how long should
     * this part last" for that equipment+part pair yet).
     */
    public function percentUsed(): ?int
    {
        $workOrder = $this->relevantWorkOrder();

        if (! $workOrder) {
            return null;
        }

        if ($workOrder->interval_days) {
            return min(100, (int) round(($this->ageInDays() / $workOrder->interval_days) * 100));
        }

        $ageInRuntimeHours = $this->ageInRuntimeHours();
        if ($workOrder->interval_hours && $ageInRuntimeHours !== null) {
            return min(100, (int) round(($ageInRuntimeHours / $workOrder->interval_hours) * 100));
        }

        return null;
    }
}
