<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
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

        $installations = PartInstallation::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereNull('removed_at')
            ->with(['equipment', 'part', 'partUnit'])
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
            'overdue_task_count' => $tasks->filter(fn (Task $task) => $task->isOverdue())->count(),
            'installations' => $installations,
            'monthly_failure_trend' => $monthlyFailureTrend,
            'average_lifetime_hours' => $lifetimeHours->isEmpty() ? null : (int) round($lifetimeHours->avg()),
            'monthly_consumption_trend' => $monthlyConsumptionTrend,
            'top_parts_consumed' => $topPartsConsumed,
        ];

        if ($includeCost) {
            $data['installed_part_value'] = $this->installedPartValue($machine, $installations);
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
    private function issueEntriesForMachine(Machine $machine, Collection $equipmentIds): Collection
    {
        return StockLedger::query()
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', now()->startOfMonth()->subMonths(11))
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
    private function topPartsConsumed(Collection $entries, bool $includeCost, int $limit = 5): array
    {
        return $entries
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
            ->take($limit)
            ->values()
            ->all();
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
}
