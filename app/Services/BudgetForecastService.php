<?php

namespace App\Services;

use App\Enums\PartReplacementStrategy;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use Illuminate\Database\Eloquent\Builder;

class BudgetForecastService
{
    private const WEEKS_PER_YEAR = 52;

    /**
     * Matches an already-built PartInstallation query to a branch — three
     * ways an equipment can resolve to one, mirroring
     * Equipment::resolvedBranchId(): production via
     * equipment.machine.line.branch_id, under an outside-line Machine via
     * equipment.machine.branch_id ("Mesin Luar Line"), or a fully
     * standalone asset via its own equipment.branch_id directly.
     */
    private function whereEquipmentInBranch(Builder $query, int $branchId): Builder
    {
        return $query->whereHas('equipment', function (Builder $q) use ($branchId) {
            $q->where('branch_id', $branchId)
                ->orWhereHas('machine', function (Builder $q2) use ($branchId) {
                    $q2->where('branch_id', $branchId)
                        ->orWhereHas('line', fn (Builder $q3) => $q3->where('branch_id', $branchId));
                });
        });
    }

    /**
     * Parts under one replacement strategy (branch-wide) that currently have
     * at least one active installation — the raw material for the budget
     * input drawer's per-part rows. `estimated_lifetime_hours` is surfaced
     * too so the drawer can flag parts that can't be forecast yet (no
     * lifetime data) before the user even tries.
     *
     * @return list<array{part_id: int, part_name: string, item_master_no: string, unit_cost: string, estimated_lifetime_hours: int|null, active_installations: int}>
     */
    public function partsForStrategy(Branch $branch, PartReplacementStrategy $strategy): array
    {
        $partsQuery = Part::query()->where('is_active', true)->where('replacement_strategy', $strategy);

        $items = [];

        foreach ($partsQuery->get() as $part) {
            $activeInstallations = $this->activeInstallationCount($branch, $part);

            if ($activeInstallations === 0) {
                continue;
            }

            $items[] = [
                'part_id' => $part->id,
                'part_name' => $part->name,
                'item_master_no' => $part->item_master_no,
                'unit_cost' => $this->unitCostFor($branch, $part),
                'estimated_lifetime_hours' => $part->estimated_lifetime_hours,
                'active_installations' => $activeInstallations,
            ];
        }

        return $items;
    }

    /**
     * The core estimate, shared by priceItems() (persisted total) and
     * lineBreakdown() (the live per-line split of that same total): how
     * many pcs/year a part is expected to need, from the average weekly
     * operating hours across whichever Lines currently have this part
     * installed in this branch (see averageWeeklyOperatingHoursFor() — each
     * Line runs its own hours, this is no longer one flat company-wide
     * number) and the part's rated lifetime — "estimasi life time (%)" tunes
     * how much of
     * that rated life to actually assume (100% = the full rated life,
     * 90% = a safety margin assuming it only reaches 90% of that before
     * wearing out, which means MORE frequent replacement, not less).
     * "estimasi kenaikan harga" inflates the unit cost for the budget year.
     *
     * Only a LifeBased part has a lifetime-driven forecast at all — a part
     * on any other strategy returns zero pcs/year here even if it happens
     * to still carry old estimated_lifetime_hours data, so that data is
     * never mistaken for a live forecast input again.
     *
     * @return array{pcs_per_year: float, unit_cost: string}
     */
    public function ratesFor(Branch $branch, Part $part, float $lifetimePercent, float $priceIncreasePercent): array
    {
        $unitCost = $this->unitCostFor($branch, $part);
        $adjustedUnitCost = bcmul($unitCost, (string) (1 + $priceIncreasePercent / 100), 4);

        if ($part->replacement_strategy !== PartReplacementStrategy::LifeBased || ! $part->estimated_lifetime_hours) {
            return ['pcs_per_year' => 0.0, 'unit_cost' => $adjustedUnitCost];
        }

        $avgWeeklyHours = $this->averageWeeklyOperatingHoursFor($branch, $part);
        $hoursPerYear = $avgWeeklyHours * self::WEEKS_PER_YEAR;

        $effectiveLifetimeHours = $part->estimated_lifetime_hours * ($lifetimePercent / 100);
        $pcsPerYear = $effectiveLifetimeHours > 0 ? $hoursPerYear / $effectiveLifetimeHours : 0.0;

        return ['pcs_per_year' => $pcsPerYear, 'unit_cost' => $adjustedUnitCost];
    }

    /**
     * Turns the drawer's per-part inputs into priced, persistable rows —
     * always re-priced from the branch's current unit cost server-side
     * (the client only ever sends percentages, never a cost).
     *
     * @param  list<array{part_id: int, estimated_lifetime_percent: float|string, price_increase_percent: float|string}>  $items
     * @return array{planned_amount: string, items: list<array{part_id: int, unit_cost: string, estimated_lifetime_percent: string, price_increase_percent: string, estimated_cost: string}>}
     */
    public function priceItems(Branch $branch, array $items): array
    {
        $plannedAmount = '0.00';
        $priced = [];

        foreach ($items as $item) {
            $part = Part::findOrFail($item['part_id']);
            $lifetimePercent = (float) $item['estimated_lifetime_percent'];
            $priceIncreasePercent = (float) ($item['price_increase_percent'] ?? 0);

            $rates = $this->ratesFor($branch, $part, $lifetimePercent, $priceIncreasePercent);
            $activeInstallations = $this->activeInstallationCount($branch, $part);
            $estimatedCost = bcmul((string) ($rates['pcs_per_year'] * $activeInstallations), $rates['unit_cost'], 2);

            $priced[] = [
                'part_id' => $part->id,
                'unit_cost' => $this->unitCostFor($branch, $part),
                'estimated_lifetime_percent' => (string) $lifetimePercent,
                'price_increase_percent' => (string) $priceIncreasePercent,
                'estimated_cost' => $estimatedCost,
            ];

            $plannedAmount = bcadd($plannedAmount, $estimatedCost, 2);
        }

        return ['planned_amount' => $plannedAmount, 'items' => $priced];
    }

    /**
     * Live per-line split of one priced item's total — "berapa pcs" per
     * Line, not per individual equipment (too granular to show once a
     * strategy spans many machines). Computed fresh from current
     * PartInstallation data rather than stored, so it never goes stale as
     * parts get moved/replaced after the budget was saved.
     *
     * @return list<array{line_id: int|null, line_name: string, active_installations: int, pcs_per_year: float, estimated_cost: string}>
     */
    public function lineBreakdown(Branch $branch, Part $part, float $lifetimePercent, float $priceIncreasePercent): array
    {
        $rates = $this->ratesFor($branch, $part, $lifetimePercent, $priceIncreasePercent);

        $installations = $this->whereEquipmentInBranch(
            PartInstallation::query()->where('part_id', $part->id)->whereNull('removed_at'),
            $branch->id
        )
            ->with('equipment.machine.line')
            ->get()
            // null (grouped together) for an outside-line installation —
            // there's no Line to split those by, so they land in one
            // "Luar Line" bucket in the breakdown below instead of crashing.
            ->groupBy(fn (PartInstallation $installation) => $installation->equipment->machine?->line_id);

        $breakdown = [];

        foreach ($installations as $group) {
            $line = $group->first()->equipment->machine?->line;
            $count = $group->count();
            $pcsPerYear = $rates['pcs_per_year'] * $count;

            $breakdown[] = [
                'line_id' => $line?->id,
                'line_name' => $line?->name ?? 'Luar Line',
                'active_installations' => $count,
                'pcs_per_year' => round($pcsPerYear, 2),
                'estimated_cost' => bcmul((string) $pcsPerYear, $rates['unit_cost'], 2),
            ];
        }

        return $breakdown;
    }

    private function activeInstallationCount(Branch $branch, Part $part): int
    {
        return $this->whereEquipmentInBranch(
            PartInstallation::query()->where('part_id', $part->id)->whereNull('removed_at'),
            $branch->id
        )->count();
    }

    /**
     * The average of ProductionLine::effectiveAvgWeeklyOperatingHours()
     * across every distinct Line that currently has an active installation
     * of this part in this branch (each Line counted once, not weighted by
     * how many installations it has) — falls back to the company-wide
     * default when the part has no installation on any Line at all (e.g.
     * only on outside-line assets, or none yet), so a forecast can still be
     * produced from the "add a part" drawer before anything is installed.
     */
    private function averageWeeklyOperatingHoursFor(Branch $branch, Part $part): int
    {
        $lines = $this->whereEquipmentInBranch(
            PartInstallation::query()->where('part_id', $part->id)->whereNull('removed_at'),
            $branch->id
        )
            ->with('equipment.machine.line')
            ->get()
            ->map(fn (PartInstallation $installation) => $installation->equipment->machine?->line)
            ->filter()
            ->unique('id');

        if ($lines->isEmpty()) {
            return CompanySetting::current()->avg_weekly_operating_hours ?: 85;
        }

        return (int) round($lines->avg(fn (ProductionLine $line) => $line->effectiveAvgWeeklyOperatingHours()));
    }

    private function unitCostFor(Branch $branch, Part $part): string
    {
        return (string) ($branch->partStocks()->where('part_id', $part->id)->value('unit_cost') ?? $part->price);
    }
}
