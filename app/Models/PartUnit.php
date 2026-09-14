<?php

namespace App\Models;

use App\Enums\PartUnitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical, trackable instance of a part — created the first time it's
 * installed and reused across every later remove/repair/reinstall cycle,
 * even onto different equipment. Remaining lifetime accumulates across all
 * of its installations rather than resetting after a repair.
 */
class PartUnit extends Model
{
    protected $fillable = [
        'part_id',
        'unit_code',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PartUnitStatus::class,
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(PartInstallation::class)->orderBy('installed_at');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(PartRepair::class)->orderBy('removed_at');
    }

    public function currentInstallation(): ?PartInstallation
    {
        return $this->installations()->whereNull('removed_at')->first();
    }

    /**
     * Human-friendly sequential label ("A", "B", "C", ... "Z", "AA", ...)
     * assigned the first time a unit of this part is installed, so multiple
     * simultaneous units of the same part on one equipment can be told apart.
     */
    public static function nextCode(int $partId): string
    {
        $index = static::where('part_id', $partId)->count();
        $label = '';

        do {
            $label = chr(65 + ($index % 26)).$label;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $label;
    }

    /**
     * Total runtime hours this unit has actually been in service, summed
     * across every installation cycle it's had (including the currently
     * active one, if any) — this is what makes lifetime usage carry over
     * across a repair instead of resetting.
     */
    public function totalRuntimeHoursUsed(): int
    {
        return $this->installations->sum(fn (PartInstallation $installation) => $installation->ageInRuntimeHours() ?? 0);
    }

    /**
     * How much of this part's estimated lifetime has been used up, as a
     * percentage — null if the part has no estimated_lifetime_hours set, so
     * there's nothing to compare against.
     */
    public function percentUsed(): ?int
    {
        $estimatedHours = $this->part->estimated_lifetime_hours;

        if (! $estimatedHours) {
            return null;
        }

        return min(100, (int) round(($this->totalRuntimeHoursUsed() / $estimatedHours) * 100));
    }

    /**
     * Every equipment this unit has ever been installed on, in order.
     */
    public function equipmentHistory(): array
    {
        return $this->installations
            ->loadMissing('equipment.machine.line')
            ->pluck('equipment')
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }
}
