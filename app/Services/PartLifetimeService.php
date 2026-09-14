<?php

namespace App\Services;

use App\Models\PartInstallation;
use Illuminate\Support\Collection;

/**
 * Surfaces installed parts whose remaining usable life has dropped below a
 * threshold — the second way a Task can be scheduled onto the PM calendar,
 * alongside picking from a Task Library recipe (see PmSchedulingService).
 */
class PartLifetimeService
{
    private const AT_RISK_THRESHOLD_PERCENT = 90;

    /**
     * @return Collection<int, PartInstallation>
     */
    public function atRiskInstallations(int $branchId): Collection
    {
        return PartInstallation::query()
            ->whereNull('removed_at')
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branchId))
            ->with(['part', 'equipment.machine.line', 'installedBy'])
            ->get()
            ->filter(fn (PartInstallation $installation) => ($installation->percentUsed() ?? 0) >= self::AT_RISK_THRESHOLD_PERCENT)
            ->sortByDesc(fn (PartInstallation $installation) => $installation->percentUsed())
            ->values();
    }
}
