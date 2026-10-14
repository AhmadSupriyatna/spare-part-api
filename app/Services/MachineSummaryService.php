<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One Machine's dashboard data — shared by the authenticated Machine Detail
 * page (`$includeCost = true`) and the public QR-scan "Monitoring Life Time
 * Mesin" page (`$includeCost = false`), so the cost/price boundary is one
 * explicit flag in one place rather than two independently-maintained
 * controllers that could drift apart and leak cost data onto the
 * unauthenticated public page by accident.
 */
class MachineSummaryService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Machine $machine, bool $includeCost): array
    {
        $equipmentIds = $machine->equipment()->pluck('id');

        $tasks = Task::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress])
            ->with(['equipment', 'assignee'])
            ->orderBy('due_date')
            ->limit(20)
            ->get();

        $scheduledTaskCount = $includeCost
            ? Task::query()
                ->whereIn('equipment_id', $equipmentIds)
                ->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress])
                ->count()
            : $tasks->count();

        if ($includeCost) {
            $lineRuntimeHours = $machine->line?->runtime_hours ?? 0;
            $overdueTaskCount = Task::query()
                ->whereIn('equipment_id', $equipmentIds)
                ->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress])
                ->where(function ($query) use ($lineRuntimeHours) {
                    $query
                        ->where(fn ($dueDateQuery) => $dueDateQuery->whereNotNull('due_date')->whereDate('due_date', '<', today()))
                        ->orWhere(fn ($runtimeQuery) => $runtimeQuery->whereNotNull('due_runtime_hours')->where('due_runtime_hours', '<=', $lineRuntimeHours));
                })
                ->count();
        } else {
            $overdueTaskCount = $tasks->filter(fn (Task $task) => $task->isOverdue())->count();
        }

        $installations = PartInstallation::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereNull('removed_at')
            ->with(['equipment', 'part', 'partUnit.repairs'])
            ->get();

        // startOfMonth() before subMonths() — anchoring on day 1 avoids
        // Carbon overflowing past a shorter month, which would otherwise
        // skip a month and duplicate another in the resulting Y-m list.
        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->reverse()->values();

        $failuresByMonth = PartReplacementRequest::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereIn('event_type', ['failure', 'breakdown'])
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['created_at'])
            ->groupBy(fn (PartReplacementRequest $request) => $request->created_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        $monthlyFailureTrend = $months->map(fn ($month) => [
            'month' => $month,
            'count' => (int) ($failuresByMonth[$month] ?? 0),
        ])->values();

        $lifetimeHours = $installations
            ->map(fn (PartInstallation $installation) => $installation->ageInRuntimeHours())
            ->filter(fn ($hours) => $hours !== null);

        $issueEntries = $this->issueEntriesForMachine($machine, $equipmentIds);

        $monthlyConsumptionTrend = $this->monthlyConsumptionTrend($issueEntries, $months, $includeCost);
        $topPartsConsumed = $this->topPartsConsumed($issueEntries, $includeCost);

        $data = [
            'equipment_count' => $equipmentIds->count(),
            'upcoming_tasks' => $tasks,
            'overdue_task_count' => $overdueTaskCount,
            'installations' => $installations,
            'monthly_failure_trend' => $monthlyFailureTrend,
            'average_lifetime_hours' => $lifetimeHours->isEmpty() ? null : (int) round($lifetimeHours->avg()),
            'monthly_consumption_trend' => $monthlyConsumptionTrend,
            'top_parts_consumed' => $topPartsConsumed,
            // Not cost data — how long a part TYPE has historically lasted
            // on this machine before being replaced, from completed
            // (removed_at set) installation cycles. Safe on the public page
            // too, but currently only rendered on Machine Detail.
            'historical_part_lifetime' => $this->historicalPartLifetime($equipmentIds),
            // The Line's own running hours, not this Machine's — a Machine
            // has no runtime-hours source of its own (see
            // PartInstallation::ageInRuntimeHours()). Null for an
            // outside-line Machine, which has no Line to read from.
            'line_runtime_hours' => $machine->line?->runtime_hours,
            'passport_install_history' => $this->passportInstallHistory($equipmentIds),
        ];

        if ($includeCost) {
            $yearIssueEntries = $this->issueEntriesForMachine($machine, $equipmentIds, now()->startOfYear());
            $data['installed_part_value'] = $this->installedPartValue($machine, $installations);
            $data['cost_by_equipment_this_year'] = $this->costByEquipmentThisYear($equipmentIds);
            $data['top_parts_consumed_this_year'] = $this->topPartsConsumed($yearIssueEntries, includeCost: true, limit: null);
            $data['upcoming_task_count'] = $scheduledTaskCount;
            $data['lifetime_hours_sample_count'] = $lifetimeHours->count();
            $data['lifetime_hours_total'] = $lifetimeHours->sum();
        }

        return $data;
    }

    /**
     * TYPE_ISSUE ledger rows traceable (through the ledger entry's
     * polymorphic reference, a Task or a PartReplacementRequest) to one of
     * this Machine's Equipment — same reference-resolution rule as
     * DashboardAnalyticsController::costHeatmap(), just pre-filtered to one
     * Machine's equipment instead of a whole Branch.
     *
     * @param  Collection<int, int>  $equipmentIds
     * @return Collection<int, StockLedger>
     */
    private function issueEntriesForMachine(Machine $machine, Collection $equipmentIds, ?Carbon $from = null): Collection
    {
        return StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', $from ?? now()->startOfMonth()->subMonths(11))
            ->with([
                'partStock.part',
                'reference' => fn ($morphTo) => $morphTo->morphWith([
                    Task::class => ['equipment'],
                    PartReplacementRequest::class => ['equipment'],
                ]),
            ])
            ->get(['id', 'part_stock_id', 'quantity_change', 'unit_cost', 'occurred_at', 'reference_type', 'reference_id'])
            ->filter(fn (StockLedger $entry) => $equipmentIds->contains($entry->reference?->equipment?->id));
    }

    /**
     * @param  Collection<int, StockLedger>  $entries
     * @param  Collection<int, string>  $months
     * @return list<array{month: string, quantity: int, cost?: string}>
     */
    private function monthlyConsumptionTrend(Collection $entries, Collection $months, bool $includeCost): array
    {
        $byMonth = $entries->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'));

        return $months->map(function (string $month) use ($byMonth, $includeCost) {
            $group = $byMonth->get($month, collect());
            $row = [
                'month' => $month,
                'quantity' => (int) $group->sum(fn (StockLedger $entry) => abs($entry->quantity_change)),
            ];

            if ($includeCost) {
                $row['cost'] = $group->reduce(
                    fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                    '0.00'
                );
            }

            return $row;
        })->values()->all();
    }

    /**
     * @param  Collection<int, StockLedger>  $entries
     * @return list<array{part_id: int, part_name: string, item_master_no: string, quantity: int, cost?: string}>
     */
    private function topPartsConsumed(Collection $entries, bool $includeCost, ?int $limit = 5): array
    {
        $parts = $entries
            ->filter(fn (StockLedger $entry) => $entry->partStock?->part !== null)
            ->groupBy(fn (StockLedger $entry) => $entry->partStock->part_id)
            ->map(function (Collection $group) use ($includeCost) {
                /** @var Part $part */
                $part = $group->first()->partStock->part;
                $row = [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                    'item_master_no' => $part->item_master_no,
                    'quantity' => (int) $group->sum(fn (StockLedger $entry) => abs($entry->quantity_change)),
                ];

                if ($includeCost) {
                    $row['cost'] = $group->reduce(
                        fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                        '0.00'
                    );
                }

                return $row;
            })
            ->sortByDesc('quantity')
            ->values();

        return ($limit === null ? $parts : $parts->take($limit))->all();
    }

    /**
     * Sum of (current branch unit_cost x active installation count) per
     * part — "how much is currently bolted onto this Machine worth right
     * now", not a historical spend figure like topPartsConsumed()'s cost.
     * Reads PartStock.unit_cost for the Machine's own resolved branch, not
     * the ledger entry's unit_cost snapshot, so it reflects today's price.
     *
     * @param  Collection<int, PartInstallation>  $installations
     */
    private function installedPartValue(Machine $machine, Collection $installations): string
    {
        $branchId = $machine->resolvedBranchId();
        $partIds = $installations->pluck('part_id')->unique();

        $unitCosts = PartStock::query()
            ->where('branch_id', $branchId)
            ->whereIn('part_id', $partIds)
            ->pluck('unit_cost', 'part_id');

        return $installations->reduce(
            fn (string $carry, PartInstallation $installation) => bcadd(
                $carry,
                (string) ($unitCosts[$installation->part_id] ?? '0.00'),
                2
            ),
            '0.00'
        );
    }

    /**
     * Total TYPE_ISSUE cost traceable to each of this Machine's Equipment,
     * for the current calendar year (Jan 1 through now) — a year-to-date
     * figure, deliberately not the trailing-12-months window the other
     * consumption charts use, since "this year" is what was asked for.
     *
     * @param  Collection<int, int>  $equipmentIds
     * @return list<array{equipment_id: int, equipment_name: string, cost: string}>
     */
    private function costByEquipmentThisYear(Collection $equipmentIds): array
    {
        $entries = StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', now()->startOfYear())
            ->whereNotNull('unit_cost')
            ->with([
                'reference' => fn ($morphTo) => $morphTo->morphWith([
                    Task::class => ['equipment'],
                    PartReplacementRequest::class => ['equipment'],
                ]),
            ])
            ->get(['id', 'quantity_change', 'unit_cost', 'reference_type', 'reference_id'])
            ->filter(fn (StockLedger $entry) => $equipmentIds->contains($entry->reference?->equipment?->id));

        return $entries
            ->groupBy(fn (StockLedger $entry) => $entry->reference->equipment->id)
            ->map(function (Collection $group) {
                /** @var Equipment $equipment */
                $equipment = $group->first()->reference->equipment;
                $cost = $group->reduce(
                    fn (string $carry, StockLedger $entry) => bcadd($carry, bcmul((string) $entry->unit_cost, (string) abs($entry->quantity_change), 2), 2),
                    '0.00'
                );

                return [
                    'equipment_id' => $equipment->id,
                    'equipment_name' => $equipment->name,
                    'cost' => $cost,
                ];
            })
            ->sortByDesc('cost')
            ->values()
            ->all();
    }

    /**
     * How many runtime hours a part TYPE has historically lasted before
     * being replaced, averaged across every COMPLETED (removed_at set)
     * installation cycle on this machine's equipment — unlike
     * average_lifetime_hours (the CURRENT age of still-active
     * installations, which understates a healthy part's eventual
     * lifespan), this only counts cycles that actually ended, so it
     * reflects a real historical replacement interval.
     *
     * @param  Collection<int, int>  $equipmentIds
     * @return list<array{part_id: int, part_name: string, average_runtime_hours: int, sample_count: int}>
     */
    private function historicalPartLifetime(Collection $equipmentIds): array
    {
        $completed = PartInstallation::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereNotNull('removed_at')
            ->whereNotNull('installed_at_runtime_hours')
            ->whereNotNull('removed_at_runtime_hours')
            ->with('part')
            ->get(['id', 'part_id', 'installed_at_runtime_hours', 'removed_at_runtime_hours']);

        return $completed
            ->filter(fn (PartInstallation $installation) => $installation->part !== null)
            ->groupBy('part_id')
            ->map(function (Collection $group) {
                /** @var Part $part */
                $part = $group->first()->part;
                $hoursUsed = $group->map(
                    fn (PartInstallation $installation) => max(0, $installation->removed_at_runtime_hours - $installation->installed_at_runtime_hours)
                );

                return [
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                    'average_runtime_hours' => (int) round($hoursUsed->avg()),
                    'sample_count' => $group->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every installation cycle (active or already removed) of a
     * has_passport Part on this Machine's equipment — unlike the regular
     * `installations` list (active only, any part), this is the full
     * physical-unit history for Passport parts specifically, since each
     * unit's own identity (unit_code, arrival date) is the point of
     * tracking it individually in the first place.
     *
     * @param  Collection<int, int>  $equipmentIds
     * @return list<array<string, mixed>>
     */
    private function passportInstallHistory(Collection $equipmentIds): array
    {
        return PartInstallation::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereHas('part', fn ($q) => $q->where('has_passport', true))
            ->with(['equipment', 'part', 'partUnit'])
            ->orderByDesc('installed_at')
            ->limit(30)
            ->get()
            ->map(fn (PartInstallation $installation) => [
                'id' => $installation->id,
                'equipment_id' => $installation->equipment_id,
                'equipment_name' => $installation->equipment?->name,
                'part_name' => $installation->part?->name,
                'unit_code' => $installation->partUnit?->unit_code,
                'installed_at' => $installation->installed_at,
                'removed_at' => $installation->removed_at,
                'is_active' => $installation->isActive(),
            ])
            ->all();
    }
}
