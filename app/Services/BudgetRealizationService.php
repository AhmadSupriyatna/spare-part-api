<?php

namespace App\Services;

use App\Enums\PartReplacementStrategy;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetManualEntry;
use App\Models\PartReplacementRequest;
use App\Models\StockLedger;
use App\Models\Task;
use Illuminate\Support\Carbon;

class BudgetRealizationService
{
    /**
     * Actual spend for one replacement-strategy/branch/year, from
     * stock_ledger's unit_cost snapshot (see the 2026-09-18 migration) —
     * never re-derived from part_stocks.unit_cost, which keeps moving. Only
     * TYPE_ISSUE rows count as "spend": a part leaving stock via a WO or
     * breakdown is the actual maintenance cost event; receiving/adjustment/
     * etc. are procurement or correction, not maintenance spend.
     *
     * Split into planned (reference is a Task, i.e. a PM/WO checklist) vs
     * unplanned (reference is a PartReplacementRequest, i.e. breakdown) —
     * trivial once the reference is there, and it's the "planned vs
     * unplanned" KPI the user asked for. `manual` is a third, independent
     * bucket: BudgetManualEntry 'actual' rows logged by hand for spend that
     * never passed through stock_ledger at all (e.g. a service call paid
     * directly) — added to `total` alongside planned/unplanned, not merged
     * into either, since it's neither a Task nor a PartReplacementRequest.
     *
     * @return array{planned: string, unplanned: string, manual: string, total: string, unpriced_transactions: int}
     */
    public function calculateRealization(Branch $branch, PartReplacementStrategy $strategy, int $year, ?Budget $budget = null): array
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = Carbon::create($year, 1, 1)->endOfYear();

        $query = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereHas('partStock', function ($q) use ($branch, $strategy) {
                $q->where('branch_id', $branch->id);
                $q->whereHas('part', fn ($q2) => $q2->where('replacement_strategy', $strategy));
            });

        $planned = '0.00';
        $unplanned = '0.00';
        $unpricedTransactions = 0;

        foreach ($query->get(['id', 'quantity_change', 'unit_cost', 'reference_type', 'reference_id']) as $entry) {
            if ($entry->unit_cost === null) {
                $unpricedTransactions++;

                continue;
            }

            $cost = bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2);

            if ($entry->reference_type === PartReplacementRequest::class) {
                $unplanned = bcadd($unplanned, $cost, 2);
            } else {
                $planned = bcadd($planned, $cost, 2);
            }
        }

        $manual = $budget
            ? $budget->manualEntries->where('type', BudgetManualEntry::TYPE_ACTUAL)->reduce(
                fn (string $carry, BudgetManualEntry $entry) => bcadd($carry, (string) $entry->amount, 2),
                '0.00',
            )
            : '0.00';

        return [
            'planned' => $planned,
            'unplanned' => $unplanned,
            'manual' => $manual,
            'total' => bcadd(bcadd($planned, $unplanned, 2), $manual, 2),
            'unpriced_transactions' => $unpricedTransactions,
        ];
    }

    /**
     * Actual spend for a whole branch/year, every replacement strategy
     * combined, bucketed by month — the "Realisasi" line in the dashboard's
     * budget projection chart. Same TYPE_ISSUE + unit_cost-snapshot rule as
     * calculateRealization(), just grouped by month instead of split
     * planned/unplanned.
     *
     * @return array<string, string> month ("Y-m") => cost, only for months with at least one priced issue
     */
    public function monthlyRealization(Branch $branch, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = Carbon::create($year, 1, 1)->endOfYear();

        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereBetween('occurred_at', [$start, $end])
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->get(['occurred_at', 'quantity_change', 'unit_cost']);

        return $entries
            ->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'))
            ->map(fn ($group) => $group->reduce(
                fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                '0.00'
            ))
            ->all();
    }
}
