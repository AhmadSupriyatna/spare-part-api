<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartMinimumStockStrategy;
use App\Enums\TaskStatus;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\ReceiveStockRequest;
use App\Http\Requests\StorePartStockRequest;
use App\Http\Requests\UpdatePartStockLocationRequest;
use App\Http\Resources\PartReservationResource;
use App\Http\Resources\PartStockResource;
use App\Http\Resources\PartUnitResource;
use App\Http\Resources\StockLedgerReportResource;
use App\Http\Resources\StockLedgerResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\PartUnit;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\StockMovementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;

class PartStockController extends Controller
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly PartLifecycleService $lifecycle,
    ) {}

    public function index(Branch $branch): AnonymousResourceCollection
    {
        $stocks = $branch->partStocks()->with(['part', 'supplier', 'location'])->get();

        $this->attachComputedAttributes($stocks, $branch->id);

        return PartStockResource::collection($stocks);
    }

    public function show(PartStock $partStock): PartStockResource
    {
        $partStock->load(['part', 'branch', 'supplier', 'location']);

        $this->attachComputedAttributes(new Collection([$partStock]), $partStock->branch_id);

        return new PartStockResource($partStock);
    }

    /**
     * Inventory Workspace's per-card numbers/badges — everything here is
     * derived, never stored, and batched across the whole list (3 queries
     * total) rather than one set of queries per row, since this runs on
     * every Inventory Workspace page load.
     *
     * reserved_quantity: how much of this stock is already spoken for by a
     * not-yet-finished Task, so "available" reads honestly even when the
     * shelf count hasn't dropped yet. Two sources, summed independently
     * (never both for the same Task — see TaskService::complete(), a
     * checklist Task never also sets its own part_stock_id):
     *   - a plain Task with part_stock_id set directly (ad-hoc, or a
     *     WorkOrder-recurring occurrence pre-filled by generateNextTask())
     *     — quantity_used if confirmed, else 1 (planned but not yet
     *     confirmed still reserves at least one unit).
     *   - a Task Library/Part Lifetime checklist row (TaskPartCheck) for
     *     this part, on a Task still Pending/InProgress in this branch —
     *     always quantity_planned=1 per row (see PmSchedulingService),
     *     counted per row rather than trusting is_replaced (that's null
     *     until the checklist is actually completed).
     *
     * active_installation_count: how many of this branch's equipment
     * currently have this part installed — the Kritis/Peringatan badge
     * below compares against this rather than minimum_stock, since "enough
     * spares to cover every unit that could fail" is the actually relevant
     * question for a spare-parts inventory.
     *
     * minimum_stock: purely a displayed reference number (not part of the
     * badge logic above), computed per Part's minimum_stock_strategy —
     * "installed" uses this branch's active_installation_count for that
     * part, "standard" uses the branch's own default_minimum_stock ("
     * Standar Minimum Plant", set on the Plant/Branch record).
     *
     * available_quantity = quantity_on_hand - reserved_quantity (never
     * negative). is_critical: available < reserved (can't even cover
     * what's already committed to open WOs). is_warning: available <
     * active_installation_count (couldn't cover every installed unit
     * failing at once) — checked only when not already critical, so a
     * card shows at most one of the two.
     */
    private function attachComputedAttributes(Collection $stocks, int $branchId): void
    {
        if ($stocks->isEmpty()) {
            return;
        }

        $defaultMinimumStock = Branch::whereKey($branchId)->value('default_minimum_stock') ?? 0;
        $openStatuses = [TaskStatus::Pending, TaskStatus::InProgress];
        $partIds = $stocks->pluck('part_id')->unique();

        $direct = Task::query()
            ->whereIn('part_stock_id', $stocks->pluck('id'))
            ->whereIn('status', $openStatuses)
            ->selectRaw('part_stock_id, SUM(COALESCE(quantity_used, 1)) as qty')
            ->groupBy('part_stock_id')
            ->pluck('qty', 'part_stock_id');

        $checklist = TaskPartCheck::query()
            ->whereIn('part_id', $partIds)
            ->whereHas('task', function ($q) use ($openStatuses, $branchId) {
                $q->whereIn('status', $openStatuses)
                    ->where(function ($q2) use ($branchId) {
                        $q2->whereHas('equipment.machine.line', fn ($q3) => $q3->where('branch_id', $branchId))
                            ->orWhereHas('equipment', fn ($q3) => $q3->where('branch_id', $branchId));
                    });
            })
            ->selectRaw('part_id, COUNT(*) as qty')
            ->groupBy('part_id')
            ->pluck('qty', 'part_id');

        $installed = PartInstallation::query()
            ->whereIn('part_id', $partIds)
            ->whereNull('removed_at')
            ->where(function ($q) use ($branchId) {
                $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branchId))
                    ->orWhereHas('equipment', fn ($q2) => $q2->where('branch_id', $branchId));
            })
            ->selectRaw('part_id, COUNT(*) as qty')
            ->groupBy('part_id')
            ->pluck('qty', 'part_id');

        $stocks->each(function (PartStock $stock) use ($direct, $checklist, $installed, $defaultMinimumStock) {
            $reserved = (int) ($direct[$stock->id] ?? 0) + (int) ($checklist[$stock->part_id] ?? 0);
            $installedCount = (int) ($installed[$stock->part_id] ?? 0);
            $available = max(0, $stock->quantity_on_hand - $reserved);

            $isCritical = $stock->quantity_on_hand < $reserved;

            $minimumStock = $stock->part?->minimum_stock_strategy === PartMinimumStockStrategy::Installed
                ? $installedCount
                : $defaultMinimumStock;

            $stock->setAttribute('reserved_quantity', $reserved);
            $stock->setAttribute('active_installation_count', $installedCount);
            $stock->setAttribute('available_quantity', $available);
            $stock->setAttribute('minimum_stock', $minimumStock);
            $stock->setAttribute('is_critical', $isCritical);
            $stock->setAttribute('is_warning', ! $isCritical && $available < $installedCount);
        });
    }

    /**
     * Receiving folds the batch's total price into the stock's unit_cost as a
     * moving (weighted) average, rather than overwriting it - handles partial
     * price increases across separate purchases gracefully:
     *   new_unit_cost = (old_unit_cost * old_qty + total_price) / (old_qty + qty_received)
     */
    public function receive(ReceiveStockRequest $request, PartStock $partStock): JsonResponse
    {
        $newUnits = $this->recordReceiving($partStock, $request->validated(), $request->user());
        $fresh = $partStock->fresh(['part', 'branch', 'supplier', 'location']);
        $this->attachComputedAttributes(new Collection([$fresh]), $fresh->branch_id);

        return (new PartStockResource($fresh))
            ->additional(['new_units' => PartUnitResource::collection($newUnits)])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Same "receive" flow as above, but for a part that may not have a
     * part_stocks row for this branch yet — the standalone Stock In page
     * lists the whole part catalog, not just parts already stocked here, so
     * this is what actually brings a part into a branch's stock the first
     * time (firstOrCreate, so receiving an already-stocked part here just
     * tops it up exactly like the row-level "Terima Barang" button does).
     */
    public function store(StorePartStockRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();

        $partStock = PartStock::firstOrCreate([
            'part_id' => $data['part_id'],
            'branch_id' => $branch->id,
        ]);

        $newUnits = $this->recordReceiving($partStock, $data, $request->user());
        $fresh = $partStock->fresh(['part', 'branch', 'supplier', 'location']);
        $this->attachComputedAttributes(new Collection([$fresh]), $fresh->branch_id);

        return (new PartStockResource($fresh))
            ->additional(['new_units' => PartUnitResource::collection($newUnits)])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  array{quantity: int, total_price: float|string, supplier_id?: int|null, notes?: string|null, arrived_at?: string|null}  $data
     * @return Collection<int, PartUnit> newly created units — empty unless this Part has_passport.
     */
    private function recordReceiving(PartStock $partStock, array $data, ?User $user): Collection
    {
        $this->stockMovements->record(
            partStock: $partStock,
            type: StockLedger::TYPE_RECEIVING,
            quantityChange: $data['quantity'],
            user: $user,
            notes: $data['notes'] ?? null,
            additionalUpdates: function (PartStock $locked, int $newBalance) use ($data) {
                $costBasis = ((float) $locked->unit_cost * $locked->quantity_on_hand) + $data['total_price'];

                $updates = [
                    'unit_cost' => $newBalance > 0 ? round($costBasis / $newBalance, 2) : $locked->unit_cost,
                ];

                if (! empty($data['supplier_id'])) {
                    $updates['supplier_id'] = $data['supplier_id'];
                }

                return $updates;
            },
            unitCostSnapshot: (string) round($data['total_price'] / $data['quantity'], 2),
        );

        if (! $partStock->part->has_passport) {
            return new Collection;
        }

        return $this->lifecycle->receiveUnits($partStock->part, $data['quantity'], $data['arrived_at'] ?? null)->loadMissing('part');
    }

    public function adjust(AdjustStockRequest $request, PartStock $partStock): JsonResponse
    {
        $data = $request->validated();

        try {
            $this->stockMovements->record(
                partStock: $partStock,
                type: StockLedger::TYPE_ADJUSTMENT,
                quantityChange: $data['quantity_change'],
                user: $request->user(),
                notes: $data['reason'],
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fresh = $partStock->fresh(['part', 'branch', 'supplier', 'location']);
        $this->attachComputedAttributes(new Collection([$fresh]), $fresh->branch_id);

        return (new PartStockResource($fresh))->response()->setStatusCode(200);
    }

    public function ledger(PartStock $partStock): AnonymousResourceCollection
    {
        return StockLedgerResource::collection(
            $partStock->ledgerEntries()->with('user')->latest('occurred_at')->paginate(25)
        );
    }

    /**
     * Part Detail's "Reservasi PM" — the actual Tasks behind
     * reserved_quantity (attachComputedAttributes() only ever summed them),
     * so a planner can see WHICH upcoming WOs this stock is already spoken
     * for by, not just how much. Same two independent sources, this time
     * kept as one row per Task rather than summed.
     *
     * @return AnonymousResourceCollection<int, PartReservationResource>
     */
    public function reservations(PartStock $partStock): AnonymousResourceCollection
    {
        $openStatuses = [TaskStatus::Pending, TaskStatus::InProgress];

        $direct = Task::query()
            ->where('part_stock_id', $partStock->id)
            ->whereIn('status', $openStatuses)
            ->with('equipment.machine.line')
            ->get()
            ->map(fn (Task $task) => [
                'task_id' => $task->id,
                'title' => $task->title,
                'due_date' => $task->due_date,
                'equipment_name' => $task->equipment?->name,
                'machine_name' => $task->equipment?->machine?->name,
                'line_name' => $task->equipment?->machine?->line?->name,
                'quantity' => $task->quantity_used ?? 1,
            ]);

        $checklist = TaskPartCheck::query()
            ->where('part_id', $partStock->part_id)
            ->whereHas('task', function ($q) use ($openStatuses, $partStock) {
                $q->whereIn('status', $openStatuses)
                    ->where(function ($q2) use ($partStock) {
                        $q2->whereHas(
                            'equipment.machine.line',
                            fn ($q3) => $q3->where('branch_id', $partStock->branch_id)
                        )->orWhereHas('equipment', fn ($q3) => $q3->where('branch_id', $partStock->branch_id));
                    });
            })
            ->with('task.equipment.machine.line')
            ->get()
            ->groupBy('task_id')
            ->map(function ($checks) {
                $task = $checks->first()->task;

                return [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'due_date' => $task->due_date,
                    'equipment_name' => $task->equipment?->name,
                    'machine_name' => $task->equipment?->machine?->name,
                    'line_name' => $task->equipment?->machine?->line?->name,
                    'quantity' => $checks->count(),
                ];
            })
            ->values();

        $rows = $direct->concat($checklist)->sortBy([
            fn ($row) => $row['due_date']?->toDateString() ?? '9999-99-99',
        ])->values();

        return PartReservationResource::collection($rows);
    }

    /**
     * Part Detail's info-card dashboard — everything bundled into one
     * response instead of four separate round-trips, since the whole point
     * of the redesign is a single scrollable page with no tab navigation.
     *
     * - monthly_usage: 12 months back including the current one, from
     *   StockLedger TYPE_ISSUE rows on this stock (grouped in PHP by
     *   Carbon::format('Y-m') rather than a DB date-group function, for
     *   the same SQLite/Postgres parity reason every other place in this
     *   app avoids raw SQL date grouping).
     * - line_machine_heatmap: currently-active installations of this part
     *   in this branch, grouped by (line, machine) — "where is it running
     *   right now", not a full historical count.
     * - failure_breakdown_count: PartReplacementRequest rows for this part
     *   in this branch whose event_type is Failure or Breakdown (never
     *   Maintenance, which is a planned/reviewed replacement, not one of
     *   the two operational-failure kinds asked for here).
     * - average_lifetime_hours: mean of PartInstallation::ageInRuntimeHours()
     *   across every installation (active or removed) of this part in this
     *   branch — the actually-achieved figure, not the Part's own static
     *   estimated_lifetime_hours rating.
     */
    public function analytics(PartStock $partStock): JsonResponse
    {
        $branchId = $partStock->branch_id;

        // startOfMonth() before subMonths() — anchoring on day 1 avoids
        // Carbon overflowing past a shorter month (e.g. a day-29-31 date
        // subtracted onto a non-leap February), which would otherwise skip
        // a month and duplicate another in the resulting Y-m list.
        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->reverse()->values();
        $usageByMonth = StockLedger::query()
            ->where('part_stock_id', $partStock->id)
            ->where('type', StockLedger::TYPE_ISSUE)
            ->where('occurred_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['occurred_at', 'quantity_change'])
            ->groupBy(fn (StockLedger $entry) => $entry->occurred_at->format('Y-m'))
            ->map(fn ($entries) => (int) $entries->sum(fn (StockLedger $entry) => abs($entry->quantity_change)));

        $monthlyUsage = $months->map(fn ($month) => [
            'month' => $month,
            'quantity' => (int) ($usageByMonth[$month] ?? 0),
        ])->values();

        $activeInstallations = PartInstallation::query()
            ->where('part_id', $partStock->part_id)
            ->whereNull('removed_at')
            ->where(function ($q) use ($branchId) {
                $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branchId))
                    ->orWhereHas('equipment', fn ($q2) => $q2->where('branch_id', $branchId));
            })
            ->with('equipment.machine.line')
            ->get();

        $heatmap = $activeInstallations
            ->groupBy(fn (PartInstallation $installation) => ($installation->equipment->machine?->line?->name ?? 'Luar Line').'|'.($installation->equipment->machine?->name ?? '-'))
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'line_name' => $first->equipment->machine?->line?->name ?? 'Luar Line',
                    'machine_name' => $first->equipment->machine?->name ?? '-',
                    'count' => $group->count(),
                ];
            })
            ->values();

        $failureBreakdownCount = PartReplacementRequest::query()
            ->where('part_id', $partStock->part_id)
            ->whereIn('event_type', ['failure', 'breakdown'])
            ->where(function ($q) use ($branchId) {
                $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branchId))
                    ->orWhereHas('equipment', fn ($q2) => $q2->where('branch_id', $branchId));
            })
            ->count();

        $allInstallations = PartInstallation::query()
            ->where('part_id', $partStock->part_id)
            ->where(function ($q) use ($branchId) {
                $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branchId))
                    ->orWhereHas('equipment', fn ($q2) => $q2->where('branch_id', $branchId));
            })
            ->with('equipment.machine.line')
            ->get();

        $lifetimeHours = $allInstallations->map(fn (PartInstallation $installation) => $installation->ageInRuntimeHours())
            ->filter(fn ($hours) => $hours !== null);

        return response()->json(['data' => [
            'monthly_usage' => $monthlyUsage,
            'line_machine_heatmap' => $heatmap,
            'failure_breakdown_count' => $failureBreakdownCount,
            'average_lifetime_hours' => $lifetimeHours->isEmpty() ? null : (int) round($lifetimeHours->avg()),
            'installations_sampled' => $allInstallations->count(),
        ]]);
    }

    /**
     * Branch-wide in/out ledger report for the "Kelola Stok" ledger tab —
     * every movement (any part, any part_stock) in this branch, optionally
     * narrowed by date range and/or part, with each row resolved to the
     * line it's traceable to (when its reference is a WO Task or a
     * breakdown PartReplacementRequest) so a line filter can also apply.
     * The line filter happens in PHP after fetching, since "which line"
     * isn't a plain column — it's derived through the polymorphic
     * reference — but the date-range/part_id/branch narrowing happens in
     * the query first, so what gets pulled from the DB stays bounded.
     */
    public function branchLedger(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = StockLedger::query()
            ->whereHas('partStock', function ($q) use ($request, $branch) {
                $q->where('branch_id', $branch->id);

                if ($request->filled('part_id')) {
                    $q->where('part_id', $request->integer('part_id'));
                }
            });

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', Date::parse($request->string('from'))->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', Date::parse($request->string('to'))->endOfDay());
        }

        $entries = $query
            ->with([
                'partStock.part',
                'user',
                'reference' => fn ($morphTo) => $morphTo->morphWith([
                    Task::class => ['equipment.machine.line'],
                    PartReplacementRequest::class => ['equipment.machine.line'],
                ]),
            ])
            ->orderByDesc('occurred_at')
            ->get();

        if ($request->filled('line_id')) {
            $lineId = $request->integer('line_id');
            $entries = $entries->filter(
                fn (StockLedger $entry) => $entry->reference?->equipment?->machine?->line_id === $lineId
            )->values();
        }

        // Which rows the frontend may offer Edit/Delete on — a receiving
        // entry only if it's the true latest entry (any type) for its
        // part_stock, looked up UNFILTERED by this request's own date/line
        // narrowing (a date-range filter must never make an older row look
        // editable just because newer rows fell outside the window).
        $partStockIds = $entries->pluck('part_stock_id')->unique()->values();
        $latestEditableIds = StockLedger::whereIn('part_stock_id', $partStockIds)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get(['id', 'part_stock_id', 'type'])
            ->unique('part_stock_id')
            ->filter(fn (StockLedger $entry) => $entry->type === StockLedger::TYPE_RECEIVING)
            ->pluck('id', 'part_stock_id');

        $entries->each(function (StockLedger $entry) use ($latestEditableIds) {
            $entry->is_editable = $latestEditableIds->get($entry->part_stock_id) === $entry->id;
        });

        return StockLedgerReportResource::collection($entries);
    }

    /**
     * Assign (or move) this part's stock to a rack/bin location.
     */
    public function updateLocation(UpdatePartStockLocationRequest $request, PartStock $partStock): PartStockResource
    {
        $partStock->update($request->validated());
        $fresh = $partStock->fresh(['part', 'branch', 'supplier', 'location']);
        $this->attachComputedAttributes(new Collection([$fresh]), $fresh->branch_id);

        return new PartStockResource($fresh);
    }

    /**
     * Reverse lookup: which parts are currently stocked at this location.
     */
    public function forLocation(Location $location): AnonymousResourceCollection
    {
        $stocks = $location->partStocks()->with('part')->get();
        $this->attachComputedAttributes($stocks, $location->branch_id);

        return PartStockResource::collection($stocks);
    }
}
