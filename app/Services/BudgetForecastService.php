<?php

namespace App\Services;

use App\Enums\PartReplacementStrategy;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\CompanySetting;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use App\Models\Task;
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
     * input drawer's per-part rows, for any of the 3 strategies now (each
     * one shows a different set of per-part inputs alongside this list —
     * see StoreBudgetRequest). `estimated_lifetime_hours` is surfaced too so
     * the drawer can flag a LifeBased part that can't be forecast yet (no
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
     * LifeBased pcs/year: from the average weekly operating hours across
     * whichever Lines currently have this part installed in this branch
     * (see averageWeeklyOperatingHoursFor() — each Line runs its own hours,
     * this is no longer one flat company-wide number) and the part's rated
     * lifetime — "estimasi life time (%)" (now a Budget-wide assumption,
     * not tuned per part) tunes how much of that rated life to actually
     * assume (100% = the full rated life, 90% = a safety margin assuming it
     * only reaches 90% of that before wearing out, which means MORE
     * frequent replacement, not less). "estimasi kenaikan harga" inflates
     * the unit cost for the budget year.
     *
     * Returns zero pcs/year for anything but a LifeBased part with
     * estimated_lifetime_hours set — callers must route FailureBased/
     * Scheduled parts through failureBasedRatesFor()/scheduledRatesFor()
     * instead, this never guesses on their behalf.
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
     * FailureBased pcs/year: no hours/lifetime math at all — failure
     * frequency isn't something to derive from equipment run-hours, so the
     * user's own per-part "estimasi jumlah kegagalan per tahun" (failure
     * rates genuinely vary a lot by part type, this is deliberately NOT a
     * global assumption like LifeBased's lifetime%) IS the pcs/year,
     * directly.
     *
     * @return array{pcs_per_year: float, unit_cost: string}
     */
    public function failureBasedRatesFor(Branch $branch, Part $part, float $failureCountPerYear, float $priceIncreasePercent): array
    {
        $unitCost = $this->unitCostFor($branch, $part);
        $adjustedUnitCost = bcmul($unitCost, (string) (1 + $priceIncreasePercent / 100), 4);

        return ['pcs_per_year' => max(0.0, $failureCountPerYear), 'unit_cost' => $adjustedUnitCost];
    }

    /**
     * Scheduled pcs/year: reuses Task Library's own real PM cadence instead
     * of asking the user to guess a raw replacement count from scratch —
     * inspectionsPerYearFor() counts how many times, over the trailing 12
     * months, a Task actually checked this part in this branch, and the
     * user only has to estimate the one genuinely unknown number: "% chance
     * this part actually gets replaced during any one of those checks."
     *
     * @return array{pcs_per_year: float, unit_cost: string, inspections_per_year: int}
     */
    public function scheduledRatesFor(Branch $branch, Part $part, float $replacementProbabilityPercent, float $priceIncreasePercent): array
    {
        $unitCost = $this->unitCostFor($branch, $part);
        $adjustedUnitCost = bcmul($unitCost, (string) (1 + $priceIncreasePercent / 100), 4);

        $inspectionsPerYear = $this->inspectionsPerYearFor($branch, $part);
        $pcsPerYear = $inspectionsPerYear * (max(0.0, $replacementProbabilityPercent) / 100);

        return [
            'pcs_per_year' => $pcsPerYear,
            'unit_cost' => $adjustedUnitCost,
            'inspections_per_year' => $inspectionsPerYear,
        ];
    }

    /**
     * How many times, over the trailing 12 months, a Task in this branch
     * had a checklist entry (TaskPartCheck) for this part — an empirical
     * stand-in for "how often is this part actually inspected", since
     * TaskLibrary's own interval_days/schedule_type metadata is optional
     * and rarely populated (see the 2026-09-16 scheduling-fields
     * migration's own docblock), while every PM Task ever actually
     * scheduled already carries this in its checklist snapshot regardless.
     * Counts every due date in the window, whatever the Task's current
     * status — this estimates inspection frequency, not completion rate.
     */
    private function inspectionsPerYearFor(Branch $branch, Part $part): int
    {
        return $this->whereEquipmentInBranch(
            Task::query()
                ->whereHas('partChecks', fn (Builder $q) => $q->where('part_id', $part->id))
                ->whereBetween('due_date', [now()->subYear(), now()]),
            $branch->id,
        )->count();
    }

    /**
     * Dispatches to the right rates*For() by the owning Budget's
     * replacement_strategy, reading each strategy's own per-part input off
     * the already-persisted BudgetItem and the Budget-wide assumptions
     * (global_lifetime_percent, global_price_increase_percent) — the one
     * place lineBreakdown()/machineBreakdown() (and nothing else) resolves
     * "how was this item's rate actually computed."
     *
     * @return array{pcs_per_year: float, unit_cost: string}
     */
    private function ratesForBudgetItem(Branch $branch, BudgetItem $item): array
    {
        $budget = $item->budget;
        $part = $item->part;
        $priceIncreasePercent = (float) $budget->global_price_increase_percent;

        return match ($budget->replacement_strategy) {
            PartReplacementStrategy::LifeBased => $this->ratesFor($branch, $part, (float) $budget->global_lifetime_percent, $priceIncreasePercent),
            PartReplacementStrategy::FailureBased => $this->failureBasedRatesFor($branch, $part, (float) $item->estimated_failure_count_per_year, $priceIncreasePercent),
            PartReplacementStrategy::Scheduled => $this->scheduledRatesFor($branch, $part, (float) $item->replacement_probability_percent, $priceIncreasePercent),
        };
    }

    /**
     * Turns the drawer's per-part inputs into priced, persistable rows —
     * always re-priced from the branch's current unit cost server-side
     * (the client only ever sends the strategy-specific input number, never
     * a cost). Which input field each item needs depends entirely on
     * $budget->replacement_strategy — the other field is always persisted
     * null.
     *
     * @param  list<array{part_id: int, estimated_failure_count_per_year?: float|string|null, replacement_probability_percent?: float|string|null}>  $items
     * @return array{planned_amount: string, items: list<array<string, mixed>>}
     */
    public function priceItems(Branch $branch, Budget $budget, array $items): array
    {
        $plannedAmount = '0.00';
        $priced = [];
        $strategy = $budget->replacement_strategy;
        $priceIncreasePercent = (float) $budget->global_price_increase_percent;

        foreach ($items as $item) {
            $part = Part::findOrFail($item['part_id']);
            $activeInstallations = $this->activeInstallationCount($branch, $part);

            $failureCount = (float) ($item['estimated_failure_count_per_year'] ?? 0);
            $replacementProbability = (float) ($item['replacement_probability_percent'] ?? 0);

            $rates = match ($strategy) {
                PartReplacementStrategy::LifeBased => $this->ratesFor($branch, $part, (float) $budget->global_lifetime_percent, $priceIncreasePercent),
                PartReplacementStrategy::FailureBased => $this->failureBasedRatesFor($branch, $part, $failureCount, $priceIncreasePercent),
                PartReplacementStrategy::Scheduled => $this->scheduledRatesFor($branch, $part, $replacementProbability, $priceIncreasePercent),
            };

            $estimatedCost = bcmul((string) ($rates['pcs_per_year'] * $activeInstallations), $rates['unit_cost'], 2);

            $priced[] = [
                'part_id' => $part->id,
                'unit_cost' => $this->unitCostFor($branch, $part),
                'estimated_failure_count_per_year' => $strategy === PartReplacementStrategy::FailureBased ? $failureCount : null,
                'replacement_probability_percent' => $strategy === PartReplacementStrategy::Scheduled ? $replacementProbability : null,
                'estimated_cost' => $estimatedCost,
            ];

            $plannedAmount = bcadd($plannedAmount, $estimatedCost, 2);
        }

        return ['planned_amount' => $plannedAmount, 'items' => $priced];
    }

    /**
     * Live per-Line split of one priced item's total — "berapa pcs" per
     * Line, not per individual equipment (too granular to show once a
     * strategy spans many machines). Computed fresh from current
     * PartInstallation data rather than stored, so it never goes stale as
     * parts get moved/replaced after the budget was saved. Every
     * outside-line/standalone installation collapses into one "Luar Line"
     * bucket — see machineBreakdown() for a finer split of that bucket by
     * individual Machine.
     *
     * @return list<array{line_id: int|null, line_name: string, active_installations: int, pcs_per_year: float, estimated_cost: string}>
     */
    public function lineBreakdown(Branch $branch, BudgetItem $item): array
    {
        $rates = $this->ratesForBudgetItem($branch, $item);

        $installations = $this->whereEquipmentInBranch(
            PartInstallation::query()->where('part_id', $item->part_id)->whereNull('removed_at'),
            $branch->id
        )
            ->with('equipment.machine.line')
            ->get()
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

    /**
     * Same idea as lineBreakdown(), split by individual Machine instead —
     * every Machine (production or "Mesin Luar Line", Machine already
     * covers both) gets its own row by name; an installation on a fully
     * standalone Equipment with no Machine at all collapses into one "Tanpa
     * Mesin" bucket.
     *
     * @return list<array{machine_id: int|null, machine_name: string, location_label: string, active_installations: int, pcs_per_year: float, estimated_cost: string}>
     */
    public function machineBreakdown(Branch $branch, BudgetItem $item): array
    {
        $rates = $this->ratesForBudgetItem($branch, $item);

        $installations = $this->whereEquipmentInBranch(
            PartInstallation::query()->where('part_id', $item->part_id)->whereNull('removed_at'),
            $branch->id
        )
            ->with('equipment.machine.line')
            ->get()
            ->groupBy(fn (PartInstallation $installation) => $installation->equipment->machine_id);

        $breakdown = [];

        foreach ($installations as $group) {
            $machine = $group->first()->equipment->machine;
            $count = $group->count();
            $pcsPerYear = $rates['pcs_per_year'] * $count;

            $locationLabel = match (true) {
                $machine === null => 'Tanpa Mesin',
                $machine->line_id !== null => $machine->line->name,
                default => 'Luar Line',
            };

            $breakdown[] = [
                'machine_id' => $machine?->id,
                'machine_name' => $machine?->name ?? 'Tanpa Mesin',
                'location_label' => $locationLabel,
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
