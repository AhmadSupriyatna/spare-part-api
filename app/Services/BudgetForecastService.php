<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Part;
use App\Models\PartInstallation;

class BudgetForecastService
{
    private const WEEKS_PER_YEAR = 52;

    /**
     * Parts in one category (branch-wide) that currently have at least one
     * active installation — the raw material for the budget input drawer's
     * per-part rows. `estimated_lifetime_hours` is surfaced too so the
     * drawer can flag parts that can't be forecast yet (no lifetime data)
     * before the user even tries.
     *
     * @return list<array{part_id: int, part_name: string, item_master_no: string, unit_cost: string, estimated_lifetime_hours: int|null, active_installations: int}>
     */
    public function partsForCategory(Branch $branch, string $category): array
    {
        $partsQuery = Part::query()->where('is_active', true);

        if ($category === '') {
            $partsQuery->where(fn ($q) => $q->whereNull('category')->orWhere('category', ''));
        } else {
            $partsQuery->where('category', $category);
        }

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
     * many pcs/year a part is expected to need, from the branch's average
     * weekly operating hours (same setting the PM schedule uses) and the
     * part's rated lifetime — "estimasi life time (%)" tunes how much of
     * that rated life to actually assume (100% = the full rated life,
     * 90% = a safety margin assuming it only reaches 90% of that before
     * wearing out, which means MORE frequent replacement, not less).
     * "estimasi kenaikan harga" inflates the unit cost for the budget year.
     *
     * @return array{pcs_per_year: float, unit_cost: string}
     */
    public function ratesFor(Branch $branch, Part $part, float $lifetimePercent, float $priceIncreasePercent): array
    {
        $avgWeeklyHours = CompanySetting::current()->avg_weekly_operating_hours ?: 85;
        $hoursPerYear = $avgWeeklyHours * self::WEEKS_PER_YEAR;

        $effectiveLifetimeHours = $part->estimated_lifetime_hours * ($lifetimePercent / 100);
        $pcsPerYear = $effectiveLifetimeHours > 0 ? $hoursPerYear / $effectiveLifetimeHours : 0.0;

        $unitCost = $this->unitCostFor($branch, $part);
        $adjustedUnitCost = bcmul($unitCost, (string) (1 + $priceIncreasePercent / 100), 4);

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
     * category spans many machines). Computed fresh from current
     * PartInstallation data rather than stored, so it never goes stale as
     * parts get moved/replaced after the budget was saved.
     *
     * @return list<array{line_id: int, line_name: string, active_installations: int, pcs_per_year: float, estimated_cost: string}>
     */
    public function lineBreakdown(Branch $branch, Part $part, float $lifetimePercent, float $priceIncreasePercent): array
    {
        $rates = $this->ratesFor($branch, $part, $lifetimePercent, $priceIncreasePercent);

        $installations = PartInstallation::query()
            ->where('part_id', $part->id)
            ->whereNull('removed_at')
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->with('equipment.machine.line')
            ->get()
            ->groupBy(fn (PartInstallation $installation) => $installation->equipment->machine->line->id);

        $breakdown = [];

        foreach ($installations as $lineId => $group) {
            $line = $group->first()->equipment->machine->line;
            $count = $group->count();
            $pcsPerYear = $rates['pcs_per_year'] * $count;

            $breakdown[] = [
                'line_id' => $line->id,
                'line_name' => $line->name,
                'active_installations' => $count,
                'pcs_per_year' => round($pcsPerYear, 2),
                'estimated_cost' => bcmul((string) $pcsPerYear, $rates['unit_cost'], 2),
            ];
        }

        return $breakdown;
    }

    private function activeInstallationCount(Branch $branch, Part $part): int
    {
        return PartInstallation::query()
            ->where('part_id', $part->id)
            ->whereNull('removed_at')
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->count();
    }

    private function unitCostFor(Branch $branch, Part $part): string
    {
        return (string) ($branch->partStocks()->where('part_id', $part->id)->value('unit_cost') ?? $part->price);
    }
}
