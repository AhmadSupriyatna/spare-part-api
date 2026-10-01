<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\StockLedger;
use App\Models\Task;
use App\Services\BudgetRealizationService;
use App\Services\PartLifetimeService;
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
    public function __construct(
        private readonly BudgetRealizationService $realization,
        private readonly PartLifetimeService $lifetime,
    ) {}

    public function show(Branch $branch): JsonResponse
    {
        return response()->json(['data' => [
            'failure_trend' => $this->failureTrend($branch),
            'cost_heatmap' => $this->costHeatmap($branch),
            'budget_projection' => $this->budgetProjection($branch),
            'failure_radial' => $this->failureRadial($branch),
            'maintenance_performance' => $this->maintenancePerformance($branch),
            'at_risk_parts' => $this->atRiskParts($branch),
            'installation_map' => $this->installationMap($branch),
            'cost_by_part' => $this->costByPart($branch),
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
     * Branch-wide failure/breakdown count alongside part-replacement count,
     * both last 12 months. Failure/breakdown is the same source
     * PublicMachineMonitoringController's per-machine version uses; the
     * replacement series counts every new PartInstallation instead (a part
     * physically going into an equipment) — deliberately simple: it counts
     * every install, first-time or not, rather than trying to classify
     * "was this really a replacement" (that distinction lives on
     * TaskPartCheck/old_installation_id for a PM-driven one, but a
     * QR-scanned failure/breakdown install never records it), so this is a
     * "part-swap activity" trend, not a precisely-filtered one.
     */
    private function failureTrend(Branch $branch): array
    {
        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->reverse()->values();
        $since = now()->startOfMonth()->subMonths(11);

        $failuresByMonth = $this->scopeEquipmentToBranch(
            PartReplacementRequest::query()
                ->whereIn('event_type', ['failure', 'breakdown'])
                ->where('created_at', '>=', $since),
            $branch->id
        )
            ->get(['created_at'])
            ->groupBy(fn (PartReplacementRequest $request) => $request->created_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        $replacementsByMonth = PartInstallation::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('installed_at', '>=', $since)
            ->get(['installed_at'])
            ->groupBy(fn (PartInstallation $installation) => $installation->installed_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        // Same actual-spend source as costHeatmap()/costByPart() (TYPE_ISSUE
        // ledger rows), grouped by month instead of Line/Machine/Part — the
        // cost line this trend combo-charts against the qty bars above.
        $costsByMonth = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', $since)
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->get(['occurred_at', 'quantity_change', 'unit_cost'])
            ->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'))
            ->map(fn ($rows) => $rows->reduce(
                fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                '0.00'
            ));

        return $months->map(fn ($month) => [
            'month' => $month,
            'failure_count' => (int) ($failuresByMonth[$month] ?? 0),
            'replacement_count' => (int) ($replacementsByMonth[$month] ?? 0),
            'replacement_cost' => (string) ($costsByMonth[$month] ?? '0.00'),
        ])->values()->all();
    }

    /**
     * "Radial Map Failure" — failure/breakdown count grouped by Line then
     * Machine (two rings), last 12 months. Same reference-resolution and
     * "Luar Line" bucketing rule as costHeatmap(), just counting reports
     * instead of summing cost.
     */
    private function failureRadial(Branch $branch): array
    {
        $requests = $this->scopeEquipmentToBranch(
            PartReplacementRequest::query()
                ->whereIn('event_type', ['failure', 'breakdown'])
                ->where('created_at', '>=', now()->startOfMonth()->subMonths(11)),
            $branch->id
        )->with('equipment.machine.line')->get(['id', 'equipment_id']);

        return $requests
            ->groupBy(fn (PartReplacementRequest $r) => ($r->equipment->machine?->line?->name ?? 'Luar Line').'|'.($r->equipment->machine?->name ?? $r->equipment->name))
            ->map(function (Collection $group) {
                $equipment = $group->first()->equipment;

                return [
                    'line_name' => $equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $equipment->machine?->name ?? $equipment->name,
                    'count' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "% WO selesai tepat waktu vs terlambat" — every completed Task with a
     * due_date, last 6 months, split by whether completed_at fell at or
     * before that due_date. Tasks with no due_date (ad-hoc/breakdown work)
     * have nothing to be "on time" against, so they're excluded rather than
     * counted either way.
     */
    private function maintenancePerformance(Branch $branch): array
    {
        $tasks = Task::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('due_date')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subMonths(6))
            ->get(['due_date', 'completed_at']);

        $onTime = $tasks->filter(fn (Task $t) => $t->completed_at->lte($t->due_date->endOfDay()))->count();

        return [
            'on_time_count' => $onTime,
            'late_count' => $tasks->count() - $onTime,
        ];
    }

    /**
     * "Life Based Part Performance" — the same at-risk list
     * PartLifetimeService already computes for auto-scheduling, just
     * surfaced read-only here, capped to the 8 closest to end-of-life.
     */
    private function atRiskParts(Branch $branch): array
    {
        return $this->lifetime->atRiskInstallations($branch->id)
            ->take(8)
            ->map(fn (PartInstallation $installation) => [
                'part_name' => $installation->part->name,
                'item_master_no' => $installation->part->item_master_no,
                'equipment_name' => $installation->equipment->name,
                'machine_name' => $installation->equipment->machine?->name,
                'line_name' => $installation->equipment->machine?->line?->name,
                'percent_used' => $installation->percentUsed(),
            ])
            ->values()
            ->all();
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
     * "Mapping Part Instalasi" — currently-installed (not removed) part
     * count grouped by Line then Machine, same "Luar Line" bucketing as
     * costHeatmap()/failureRadial(). A snapshot of where parts physically
     * sit right now, not a 12-month activity window like the other charts.
     */
    private function installationMap(Branch $branch): array
    {
        $installations = $this->scopeEquipmentToBranch(
            PartInstallation::query()->whereNull('removed_at'),
            $branch->id
        )->with('equipment.machine.line')->get(['id', 'equipment_id']);

        return $installations
            ->groupBy(fn (PartInstallation $i) => ($i->equipment->machine?->line?->name ?? 'Luar Line').'|'.($i->equipment->machine?->name ?? $i->equipment->name))
            ->map(function (Collection $group) {
                $equipment = $group->first()->equipment;

                return [
                    'line_name' => $equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $equipment->machine?->name ?? $equipment->name,
                    'count' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Same actual-spend source as costHeatmap() (TYPE_ISSUE ledger rows,
     * last 12 months), grouped by Part instead of Line/Machine — "Peta
     * Biaya Failure & Replacement" per-part breakdown, top 10 by cost.
     */
    private function costByPart(Branch $branch): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', now()->startOfMonth()->subMonths(11))
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->with('partStock.part')
            ->get(['id', 'quantity_change', 'unit_cost', 'part_stock_id']);

        return $entries
            ->groupBy(fn (StockLedger $entry) => $entry->partStock->part_id)
            ->map(function (Collection $group) {
                $part = $group->first()->partStock->part;
                $cost = $group->reduce(
                    fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                    '0.00'
                );

                return [
                    'part_name' => $part->name,
                    'item_master_no' => $part->item_master_no,
                    'cost' => $cost,
                ];
            })
            ->sortByDesc(fn (array $row) => (float) $row['cost'])
            ->take(10)
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
