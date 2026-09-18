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
     * Estimates a coming year's planned replacement cost for one part
     * category at one branch, from each part's estimated lifetime hours
     * and how many slots of that part are currently installed there.
     *
     * This is a steady-state estimate — how many times a slot would
     * cycle through the part's full life over a year at the branch's
     * typical operating pace — not a phase-aware one: it deliberately
     * ignores how much life the currently-installed unit has already
     * used up, so a part replaced yesterday still contributes its
     * average annual rate. Good enough for an annual planning number
     * ("estimasi dulu"); a phase-aware version (first replacement lands
     * in month X, repeats every Y weeks after) would be more precise
     * but isn't needed yet.
     *
     * @return array{
     *     planned_amount: string,
     *     items: list<array{part_id: int, part_name: string, item_master_no: string, unit_cost: string, active_installations: int, expected_replacements_per_year: float, estimated_cost: string}>,
     *     excluded_parts: list<array{part_id: int, part_name: string, item_master_no: string, reason: string}>,
     * }
     */
    public function estimateCategoryBudget(Branch $branch, string $category): array
    {
        $avgWeeklyHours = CompanySetting::current()->avg_weekly_operating_hours ?: 85;
        $hoursPerYear = $avgWeeklyHours * self::WEEKS_PER_YEAR;

        $partsQuery = Part::query()->where('is_active', true);
        if ($category === '') {
            $partsQuery->where(fn ($q) => $q->whereNull('category')->orWhere('category', ''));
        } else {
            $partsQuery->where('category', $category);
        }

        $items = [];
        $excludedParts = [];
        $plannedAmount = '0.00';

        foreach ($partsQuery->get() as $part) {
            $activeInstallations = PartInstallation::query()
                ->where('part_id', $part->id)
                ->whereNull('removed_at')
                ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
                ->count();

            if ($activeInstallations === 0) {
                continue;
            }

            if (! $part->estimated_lifetime_hours) {
                $excludedParts[] = [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                    'item_master_no' => $part->item_master_no,
                    'reason' => 'Belum ada data estimasi umur pakai (jam) untuk part ini.',
                ];

                continue;
            }

            $unitCost = (string) ($branch->partStocks()->where('part_id', $part->id)->value('unit_cost') ?? $part->price);

            $expectedReplacementsPerYear = $hoursPerYear / $part->estimated_lifetime_hours;
            $estimatedCost = bcmul((string) ($expectedReplacementsPerYear * $activeInstallations), $unitCost, 2);

            $items[] = [
                'part_id' => $part->id,
                'part_name' => $part->name,
                'item_master_no' => $part->item_master_no,
                'unit_cost' => $unitCost,
                'active_installations' => $activeInstallations,
                'expected_replacements_per_year' => round($expectedReplacementsPerYear, 2),
                'estimated_cost' => $estimatedCost,
            ];

            $plannedAmount = bcadd($plannedAmount, $estimatedCost, 2);
        }

        return [
            'planned_amount' => $plannedAmount,
            'items' => $items,
            'excluded_parts' => $excludedParts,
        ];
    }
}
