<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\PartReplacementRequest;
use App\Models\StockLedger;
use App\Models\Task;
use App\Services\BudgetRealizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chart data for the main Dashboard: everything bundled into one response
 * (same "one round trip" philosophy as PartStockController::analytics() and
 * PublicMachineMonitoringController) rather than one request per chart.
 * Stock health (Kritis/Peringatan/Normal) and Reorder counts are NOT here —
 * those are already fetched elsewhere (Kelola Stok's part-stocks list, the
 * Reorder Requests page) and the dashboard just reuses that same data
 * client-side, so there is exactly one source of truth for each.
 *
 * Gated the same as Budget (admin_spare_part|supervisor|superadmin, see
 * routes/api.php) — cost heatmap and budget projection are the same
 * planning/finance concern Budget already restricts from Engineer.
 */
class DashboardAnalyticsController extends Controller
{
    public function __construct(private readonly BudgetRealizationService $realization) {}

    public function show(Branch $branch): JsonResponse
    {
        return response()->json(['data' => [
            'failure_trend' => $this->failureTrend($branch),
            'cost_heatmap' => $this->costHeatmap($branch),
            'budget_projection' => $this->budgetProjection($branch),
        ]]);
    }

    /**
     * Three ways an Equipment resolves to a branch, mirroring
     * Equipment::resolvedBranchId(): production via
     * equipment.machine.line.branch_id, under an outside-line Machine via
     * equipment.machine.branch_id ("Mesin Luar Line"), or a standalone asset
     * via its own equipment.branch_id.
     */
    private function scopeEquipmentToBranch(Builder $query, int $branchId): Builder
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
     * Branch-wide failure/breakdown count, last 12 months — same shape as
     * PublicMachineMonitoringController's per-machine version, just scoped
     * to every equipment in the branch instead of one machine's.
     */
    private function failureTrend(Branch $branch): array
    {
        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->reverse()->values();

        $countsByMonth = $this->scopeEquipmentToBranch(
            PartReplacementRequest::query()
                ->whereIn('event_type', ['failure', 'breakdown'])
                ->where('created_at', '>=', now()->startOfMonth()->subMonths(11)),
            $branch->id
        )
            ->get(['created_at'])
            ->groupBy(fn (PartReplacementRequest $request) => $request->created_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        return $months->map(fn ($month) => [
            'month' => $month,
            'count' => (int) ($countsByMonth[$month] ?? 0),
        ])->values()->all();
    }

    /**
     * Actual spend (TYPE_ISSUE ledger rows, same rule as
     * BudgetRealizationService) over the last 12 months, grouped by which
     * Line/Machine it's traceable to through the ledger entry's polymorphic
     * reference (a Task or a PartReplacementRequest) — mirrors
     * PartStockController::branchLedger()'s reference resolution. An
     * outside-line Machine's equipment (no Line) and a fully standalone
     * asset (no Machine at all) both fall into a "Luar Line" line bucket,
     * using the asset's own name as the "machine" axis value when it has no
     * real Machine to report.
     *
     * @return list<array{line_name: string, machine_name: string, cost: string}>
     */
    private function costHeatmap(Branch $branch): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', now()->startOfMonth()->subMonths(11))
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->with([
                'reference' => fn ($morphTo) => $morphTo->morphWith([
                    Task::class => ['equipment.machine.line'],
                    PartReplacementRequest::class => ['equipment.machine.line'],
                ]),
            ])
            ->get(['id', 'quantity_change', 'unit_cost', 'reference_type', 'reference_id']);

        return $entries
            ->filter(fn (StockLedger $entry) => $entry->reference?->equipment !== null)
            ->groupBy(function (StockLedger $entry) {
                $equipment = $entry->reference->equipment;

                return ($equipment->machine?->line?->name ?? 'Luar Line').'|'.($equipment->machine?->name ?? $equipment->name);
            })
            ->map(function (Collection $group) {
                /** @var Equipment $equipment */
                $equipment = $group->first()->reference->equipment;
                $cost = $group->reduce(
                    fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                    '0.00'
                );

                return [
                    'line_name' => $equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $equipment->machine?->name ?? $equipment->name,
                    'cost' => $cost,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Proyeksi Biaya" — this year's total planned Budget (every
     * replacement strategy summed) spread evenly across 12 months as a
     * straight-line trajectory, plotted cumulatively against actual
     * cumulative spend so far (BudgetRealizationService::monthlyRealization()).
     * `actual_cumulative` is null for a month that hasn't happened yet, so
     * the chart draws the real line stopping at "today" instead of a
     * misleading flat tail.
     */
    private function budgetProjection(Branch $branch): array
    {
        $year = (int) now()->format('Y');

        $plannedTotal = $branch->budgets()->where('year', $year)->get()
            ->reduce(fn (string $carry, Budget $budget) => bcadd($carry, $budget->totalAmount(), 2), '0.00');

        $plannedPerMonth = bcdiv($plannedTotal, '12', 4);
        $realizedByMonth = $this->realization->monthlyRealization($branch, $year);
        $now = now();

        $plannedCumulative = '0.00';
        $actualCumulative = '0.00';
        $rows = [];

        foreach (range(1, 12) as $monthNumber) {
            $month = sprintf('%d-%02d', $year, $monthNumber);
            $plannedCumulative = bcadd($plannedCumulative, $plannedPerMonth, 2);
            $hasHappened = Carbon::createFromFormat('Y-m', $month)->lte($now);

            if ($hasHappened) {
                $actualCumulative = bcadd($actualCumulative, $realizedByMonth[$month] ?? '0.00', 2);
            }

            $rows[] = [
                'month' => $month,
                'planned_cumulative' => $plannedCumulative,
                'actual_cumulative' => $hasHappened ? $actualCumulative : null,
            ];
        }

        return [
            'year' => $year,
            'planned_total' => $plannedTotal,
            'rows' => $rows,
        ];
    }
}
