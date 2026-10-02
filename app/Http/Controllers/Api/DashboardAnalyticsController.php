<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartReplacementRequestItem;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Services\BudgetRealizationService;
use App\Services\PartLifetimeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Every time-windowed chart here is scoped to one calendar year, picked
     * via the dashboard's year dropdown (`?year=`, defaults to the current
     * year) — NOT a rolling trailing window. The handful of snapshot charts
     * (installation_map, installation_sunburst, stock_value,
     * equipment_coverage, technician_workload, at_risk_parts) describe
     * right-now state and deliberately ignore `$year` entirely.
     */
    public function show(Request $request, Branch $branch): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        if ($year <= 0) {
            $year = (int) now()->year;
        }

        return response()->json(['data' => [
            'year' => $year,
            'failure_trend' => $this->failureTrend($branch, $year),
            'cost_heatmap' => $this->costHeatmap($branch, $year),
            'budget_projection' => $this->budgetProjection($branch, $year),
            'failure_radial' => $this->failureRadial($branch, $year),
            'maintenance_performance' => $this->maintenancePerformance($branch, $year),
            'at_risk_parts' => $this->atRiskParts($branch),
            'installation_map' => $this->installationMap($branch),
            'cost_by_part' => $this->costByPart($branch, $year),
            'stock_value' => $this->stockValue($branch),
            'pm_vs_failure_cost' => $this->pmVsFailureCost($branch, $year),
            'equipment_coverage' => $this->equipmentCoverage($branch),
            'technician_workload' => $this->technicianWorkload($branch),
            'part_movement' => $this->partMovement($branch, $year),
            'stock_movement_trend' => $this->stockMovementTrend($branch, $year),
            'installation_sunburst' => $this->installationSunburst($branch),
            'cost_sunburst' => $this->costSunburst($branch, $year),
            'scheduled_vs_failure_trend' => $this->scheduledVsFailureTrend($branch, $year),
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
     * One row per month of `$year`, Jan-Dec. Shared by every month-bucketed
     * chart in this controller instead of each re-deriving its own
     * range(0,11)/reverse() dance.
     *
     * @return Collection<int, string>
     */
    private function monthsOfYear(int $year): Collection
    {
        return collect(range(1, 12))->map(fn ($m) => sprintf('%d-%02d', $year, $m));
    }

    /**
     * Branch-wide failure/breakdown count alongside part-replacement count,
     * both scoped to `$year`. Failure/breakdown is the same source
     * PublicMachineMonitoringController's per-machine version uses; the
     * replacement series counts every new PartInstallation instead (a part
     * physically going into an equipment) — deliberately simple: it counts
     * every install, first-time or not, rather than trying to classify
     * "was this really a replacement" (that distinction lives on
     * TaskPartCheck/old_installation_id for a PM-driven one, but a
     * QR-scanned failure/breakdown install never records it), so this is a
     * "part-swap activity" trend, not a precisely-filtered one.
     */
    private function failureTrend(Branch $branch, int $year): array
    {
        $months = $this->monthsOfYear($year);

        $failuresByMonth = $this->scopeEquipmentToBranch(
            PartReplacementRequest::query()
                ->whereIn('event_type', ['failure', 'breakdown'])
                ->whereYear('created_at', $year),
            $branch->id
        )
            ->get(['created_at'])
            ->groupBy(fn (PartReplacementRequest $request) => $request->created_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        $replacementsByMonth = PartInstallation::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereYear('installed_at', $year)
            ->get(['installed_at'])
            ->groupBy(fn (PartInstallation $installation) => $installation->installed_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        // Same actual-spend source as costHeatmap()/costByPart() (TYPE_ISSUE
        // ledger rows), grouped by month instead of Line/Machine/Part — the
        // cost line this trend combo-charts against the qty bars above.
        $costsByMonth = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
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
     * Machine (two rings), scoped to `$year`. Same reference-resolution and
     * "Luar Line" bucketing rule as costHeatmap(), just counting reports
     * instead of summing cost.
     */
    private function failureRadial(Branch $branch, int $year): array
    {
        $requests = $this->scopeEquipmentToBranch(
            PartReplacementRequest::query()
                ->whereIn('event_type', ['failure', 'breakdown'])
                ->whereYear('created_at', $year),
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
     * due_date in `$year`, split by whether completed_at fell at or before
     * that due_date. Tasks with no due_date (ad-hoc/breakdown work) have
     * nothing to be "on time" against, so they're excluded rather than
     * counted either way.
     */
    private function maintenancePerformance(Branch $branch, int $year): array
    {
        $tasks = Task::query()
            ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('due_date')
            ->whereNotNull('completed_at')
            ->whereYear('completed_at', $year)
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
     * surfaced read-only here, capped to the 20 closest to end-of-life (the
     * frontend renders this as a scrollable list, not a fixed-size chart,
     * so it can afford to show more than a handful).
     */
    private function atRiskParts(Branch $branch): array
    {
        return $this->lifetime->atRiskInstallations($branch->id)
            ->take(20)
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
     * BudgetRealizationService) in `$year`, grouped by which Line/Machine
     * it's traceable to through the ledger entry's polymorphic reference (a
     * Task or a PartReplacementRequest) — mirrors
     * PartStockController::branchLedger()'s reference resolution. An
     * outside-line Machine's equipment (no Line) and a fully standalone
     * asset (no Machine at all) both fall into a "Luar Line" line bucket,
     * using the asset's own name as the "machine" axis value when it has no
     * real Machine to report.
     *
     * @return list<array{line_name: string, machine_name: string, cost: string}>
     */
    private function costHeatmap(Branch $branch, int $year): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
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
     * scoped to `$year`), grouped by Part instead of Line/Machine — "Peta
     * Biaya Failure & Replacement" per-part breakdown, top 10 by cost.
     */
    private function costByPart(Branch $branch, int $year): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
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
     * "Proyeksi Biaya" — `$year`'s total planned Budget (every replacement
     * strategy summed) spread evenly across 12 months as a straight-line
     * trajectory, plotted cumulatively against actual cumulative spend so
     * far (BudgetRealizationService::monthlyRealization()).
     * `actual_cumulative` is null for a month that hasn't happened yet (for
     * a past year, every month already has; for a future year, none have),
     * so the chart draws the real line stopping at "today" instead of a
     * misleading flat tail.
     */
    private function budgetProjection(Branch $branch, int $year): array
    {
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

    /**
     * Total Rp value currently sitting in this branch's stock
     * (quantity_on_hand * unit_cost, summed across every PartStock row) —
     * a snapshot, not a time window like the other charts here.
     */
    private function stockValue(Branch $branch): string
    {
        return PartStock::where('branch_id', $branch->id)
            ->get(['quantity_on_hand', 'unit_cost'])
            ->reduce(
                fn (string $carry, PartStock $stock) => bcadd($carry, bcmul((string) $stock->quantity_on_hand, (string) $stock->unit_cost, 2), 2),
                '0.00'
            );
    }

    /**
     * "Sebaran Cost PM vs Failure" — same actual-spend source as
     * costHeatmap()/costByPart() (TYPE_ISSUE ledger rows, scoped to
     * `$year`), split into two buckets by what the ledger entry's reference
     * actually is: a Task scheduled from a Task Library or Part Lifetime
     * trigger is planned work (PM); an ad-hoc/breakdown Task, a
     * PartReplacementRequest (the public breakdown QR flow), or an
     * Fp3Request (repair/fabrication request) are all reactive work
     * (Failure). A null/unresolvable reference is excluded, same as
     * costHeatmap()'s own rule.
     *
     * @return array{pm_cost: string, failure_cost: string}
     */
    private function pmVsFailureCost(Branch $branch, int $year): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->with(['reference' => fn ($morphTo) => $morphTo->morphWith([
                Task::class => ['partChecks'],
            ])])
            ->get(['id', 'quantity_change', 'unit_cost', 'reference_type', 'reference_id']);

        $pmCost = '0.00';
        $failureCost = '0.00';

        foreach ($entries as $entry) {
            if ($entry->reference === null) {
                continue;
            }

            $cost = bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2);
            $isPm = $entry->reference instanceof Task
                && ($entry->reference->task_library_id !== null || $entry->reference->partChecks->isNotEmpty());

            if ($isPm) {
                $pmCost = bcadd($pmCost, $cost, 2);
            } else {
                $failureCost = bcadd($failureCost, $cost, 2);
            }
        }

        return ['pm_cost' => $pmCost, 'failure_cost' => $failureCost];
    }

    /**
     * How many Equipment in this branch have zero active Task Library
     * recipes registered — a PM coverage gap, not something any other chart
     * here surfaces (every other chart only sees work that's already
     * scheduled, never the equipment nobody's scheduled anything for).
     *
     * @return array{total_equipment: int, without_library: int}
     */
    private function equipmentCoverage(Branch $branch): array
    {
        $branchEquipment = fn () => Equipment::where('branch_id', $branch->id)
            ->orWhereHas('machine', fn ($q) => $q->where('branch_id', $branch->id)
                ->orWhereHas('line', fn ($q2) => $q2->where('branch_id', $branch->id)));

        return [
            'total_equipment' => $branchEquipment()->count(),
            'without_library' => $branchEquipment()
                ->whereDoesntHave('taskLibraries', fn ($q) => $q->where('is_active', true))
                ->count(),
        ];
    }

    /**
     * "Beban Kerja per Teknisi" — how many Pending/In Progress Tasks each
     * person currently has claimed in this branch, top 8. Grouped in PHP
     * (same convention as failureTrend()'s month grouping) rather than a
     * raw SQL GROUP BY, for SQLite/Postgres parity across test and dev.
     *
     * @return list<array{name: string, open_count: int}>
     */
    private function technicianWorkload(Branch $branch): array
    {
        $tasks = $this->scopeEquipmentToBranch(
            Task::query()->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress])->whereNotNull('assigned_to'),
            $branch->id
        )->with('assignee')->get(['assigned_to']);

        return $tasks
            ->groupBy('assigned_to')
            ->map(fn (Collection $group) => [
                'name' => $group->first()->assignee->name,
                'open_count' => $group->count(),
            ])
            ->sortByDesc('open_count')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Fast movers (top 5 parts by quantity issued in `$year` — same scope as
     * costByPart(), summing quantity instead of cost) alongside slow movers
     * (parts that currently have stock on hand but recorded ZERO issues in
     * that same year — "capital sitting on the shelf", a stronger signal
     * than just "lowest issued quantity" since a part issued once barely
     * counts as "moving" either).
     *
     * @return array{fast_moving: list<array{part_name:string,item_master_no:string,qty_issued:int}>, slow_moving: list<array{part_name:string,item_master_no:string,quantity_on_hand:int}>}
     */
    private function partMovement(Branch $branch, int $year): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->with('partStock.part')
            ->get(['id', 'quantity_change', 'part_stock_id']);

        $fastMoving = $entries
            ->groupBy(fn (StockLedger $entry) => $entry->partStock->part_id)
            ->map(function (Collection $group) {
                $part = $group->first()->partStock->part;

                return [
                    'part_name' => $part->name,
                    'item_master_no' => $part->item_master_no,
                    'qty_issued' => $group->sum(fn (StockLedger $entry) => abs($entry->quantity_change)),
                ];
            })
            ->sortByDesc('qty_issued')
            ->take(5)
            ->values()
            ->all();

        $slowMoving = PartStock::where('branch_id', $branch->id)
            ->where('quantity_on_hand', '>', 0)
            ->whereDoesntHave('ledgerEntries', fn ($q) => $q->where('type', StockLedger::TYPE_ISSUE)->whereYear('occurred_at', $year))
            ->with('part')
            ->orderByDesc('quantity_on_hand')
            ->take(5)
            ->get()
            ->map(fn (PartStock $stock) => [
                'part_name' => $stock->part->name,
                'item_master_no' => $stock->part->item_master_no,
                'quantity_on_hand' => $stock->quantity_on_hand,
            ])
            ->all();

        return ['fast_moving' => $fastMoving, 'slow_moving' => $slowMoving];
    }

    /**
     * "Tren Keluar-Masuk Stok" — monthly received vs issued quantity,
     * scoped to `$year`.
     *
     * @return list<array{month: string, in_qty: int, out_qty: int}>
     */
    private function stockMovementTrend(Branch $branch, int $year): array
    {
        $months = $this->monthsOfYear($year);

        $baseQuery = fn (string $type) => StockLedger::query()
            ->where('type', $type)
            ->whereYear('occurred_at', $year)
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id));

        $inByMonth = $baseQuery(StockLedger::TYPE_RECEIVING)
            ->get(['occurred_at', 'quantity_change'])
            ->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->sum('quantity_change'));

        $outByMonth = $baseQuery(StockLedger::TYPE_ISSUE)
            ->get(['occurred_at', 'quantity_change'])
            ->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->sum(fn (StockLedger $entry) => abs($entry->quantity_change)));

        return $months->map(fn ($month) => [
            'month' => $month,
            'in_qty' => (int) ($inByMonth[$month] ?? 0),
            'out_qty' => (int) ($outByMonth[$month] ?? 0),
        ])->values()->all();
    }

    /**
     * "Mapping Part" sunburst — same currently-installed snapshot as
     * installationMap(), but kept flat per Line/Machine/Equipment/Part leaf
     * (instead of pre-aggregated to Line/Machine) so the frontend can build
     * all four sunburst rings itself, drilling past Machine into Equipment
     * and Part. Same "Luar Line" bucketing as installationMap().
     *
     * @return list<array{line_name: string, machine_name: string, equipment_name: string, part_name: string, count: int}>
     */
    private function installationSunburst(Branch $branch): array
    {
        $installations = $this->scopeEquipmentToBranch(
            PartInstallation::query()->whereNull('removed_at'),
            $branch->id
        )->with('equipment.machine.line', 'part')->get(['id', 'equipment_id', 'part_id']);

        return $installations
            ->groupBy(function (PartInstallation $i) {
                $equipment = $i->equipment;

                return implode('|', [
                    $equipment->machine?->line?->name ?? 'Luar Line',
                    $equipment->machine?->name ?? $equipment->name,
                    $equipment->name,
                    $i->part->name,
                ]);
            })
            ->map(function (Collection $group) {
                /** @var PartInstallation $first */
                $first = $group->first();
                $equipment = $first->equipment;

                return [
                    'line_name' => $equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $equipment->machine?->name ?? $equipment->name,
                    'equipment_name' => $equipment->name,
                    'part_name' => $first->part->name,
                    'count' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Cost per Line/Equipment" sunburst — same actual-spend source as
     * costHeatmap() (TYPE_ISSUE ledger rows), scoped to `$year`, kept flat
     * per Line/Machine/Equipment/Part leaf instead of pre-aggregated to
     * Line/Machine, so the frontend can drill the sunburst past Machine into
     * Equipment and Part, the same four rings as installationSunburst().
     *
     * @return list<array{line_name: string, machine_name: string, equipment_name: string, part_name: string, cost: string}>
     */
    private function costSunburst(Branch $branch, int $year): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->whereYear('occurred_at', $year)
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereNotNull('unit_cost')
            ->with([
                'partStock.part',
                'reference' => fn ($morphTo) => $morphTo->morphWith([
                    Task::class => ['equipment.machine.line'],
                    PartReplacementRequest::class => ['equipment.machine.line'],
                ]),
            ])
            ->get(['id', 'quantity_change', 'unit_cost', 'reference_type', 'reference_id', 'part_stock_id']);

        return $entries
            ->filter(fn (StockLedger $entry) => $entry->reference?->equipment !== null)
            ->groupBy(function (StockLedger $entry) {
                $equipment = $entry->reference->equipment;

                return implode('|', [
                    $equipment->machine?->line?->name ?? 'Luar Line',
                    $equipment->machine?->name ?? $equipment->name,
                    $equipment->name,
                    $entry->partStock->part->name,
                ]);
            })
            ->map(function (Collection $group) {
                /** @var StockLedger $first */
                $first = $group->first();
                $equipment = $first->reference->equipment;
                $cost = $group->reduce(
                    fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                    '0.00'
                );

                return [
                    'line_name' => $equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $equipment->machine?->name ?? $equipment->name,
                    'equipment_name' => $equipment->name,
                    'part_name' => $first->partStock->part->name,
                    'cost' => $cost,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Tren Scheduled vs Failure" — every PartInstallation in `$year`,
     * classified by HOW it was created rather than re-using
     * failureTrend()'s counts (that failure_count is breakdown *reports*,
     * this is actually-completed installs): a TaskPartCheck pointing its
     * part_installation_id at the row means a PM task replaced it
     * ("scheduled"); a PartReplacementRequestItem pointing its
     * new_installation_id at the row means the public breakdown/QR flow
     * created it ("failure"). Both creation paths go through
     * PartLifecycleService::install(), so the install row itself carries no
     * marker — the marker lives on whichever of those two linking tables has
     * a row, and they're mutually exclusive by construction. A row matching
     * neither (manual/legacy install) counts toward neither series.
     *
     * @return list<array{month: string, scheduled_count: int, failure_count: int}>
     */
    private function scheduledVsFailureTrend(Branch $branch, int $year): array
    {
        $months = $this->monthsOfYear($year);

        $installations = $this->scopeEquipmentToBranch(
            PartInstallation::query()->whereYear('installed_at', $year),
            $branch->id
        )->get(['id', 'installed_at']);

        $installationIds = $installations->pluck('id');
        $scheduledIds = TaskPartCheck::query()->whereIn('part_installation_id', $installationIds)->pluck('part_installation_id')->flip();
        $failureIds = PartReplacementRequestItem::query()->whereIn('new_installation_id', $installationIds)->pluck('new_installation_id')->flip();

        $scheduledByMonth = $installations
            ->filter(fn (PartInstallation $i) => isset($scheduledIds[$i->id]))
            ->groupBy(fn (PartInstallation $i) => $i->installed_at->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->count());

        $failureByMonth = $installations
            ->filter(fn (PartInstallation $i) => isset($failureIds[$i->id]))
            ->groupBy(fn (PartInstallation $i) => $i->installed_at->format('Y-m'))
            ->map(fn (Collection $rows) => $rows->count());

        return $months->map(fn ($month) => [
            'month' => $month,
            'scheduled_count' => (int) ($scheduledByMonth[$month] ?? 0),
            'failure_count' => (int) ($failureByMonth[$month] ?? 0),
        ])->values()->all();
    }
}
