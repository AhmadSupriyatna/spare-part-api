<?php

namespace App\Services;

use App\Enums\PartReplacementStrategy;
use App\Models\Branch;
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
     * unplanned" KPI the user asked for.
     *
     * @return array{planned: string, unplanned: string, total: string, unpriced_transactions: int}
     */
    public function calculateRealization(Branch $branch, PartReplacementStrategy $strategy, int $year): array
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

        return [
            'planned' => $planned,
            'unplanned' => $unplanned,
            'total' => bcadd($planned, $unplanned, 2),
            'unpriced_transactions' => $unpricedTransactions,
        ];
    }
}
