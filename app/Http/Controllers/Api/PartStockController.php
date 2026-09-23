<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\ReceiveStockRequest;
use App\Http\Requests\StorePartStockRequest;
use App\Http\Requests\UpdatePartStockLocationRequest;
use App\Http\Resources\PartStockResource;
use App\Http\Resources\StockLedgerReportResource;
use App\Http\Resources\StockLedgerResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;

class PartStockController extends Controller
{
    public function __construct(private readonly StockMovementService $stockMovements) {}

    public function index(Branch $branch): AnonymousResourceCollection
    {
        return PartStockResource::collection(
            $branch->partStocks()->with(['part', 'supplier', 'location'])->get()
        );
    }

    public function show(PartStock $partStock): PartStockResource
    {
        return new PartStockResource($partStock->load(['part', 'branch', 'supplier', 'location']));
    }

    /**
     * Receiving folds the batch's total price into the stock's unit_cost as a
     * moving (weighted) average, rather than overwriting it - handles partial
     * price increases across separate purchases gracefully:
     *   new_unit_cost = (old_unit_cost * old_qty + total_price) / (old_qty + qty_received)
     */
    public function receive(ReceiveStockRequest $request, PartStock $partStock): JsonResponse
    {
        $this->recordReceiving($partStock, $request->validated(), $request->user());

        return (new PartStockResource($partStock->fresh(['part', 'branch', 'supplier', 'location'])))
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

        $this->recordReceiving($partStock, $data, $request->user());

        return (new PartStockResource($partStock->fresh(['part', 'branch', 'supplier', 'location'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  array{quantity: int, total_price: float|string, supplier_id?: int|null, notes?: string|null}  $data
     */
    private function recordReceiving(PartStock $partStock, array $data, ?User $user): void
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

        return (new PartStockResource($partStock->fresh(['part', 'branch', 'supplier', 'location'])))
            ->response()
            ->setStatusCode(200);
    }

    public function ledger(PartStock $partStock): AnonymousResourceCollection
    {
        return StockLedgerResource::collection(
            $partStock->ledgerEntries()->with('user')->latest('occurred_at')->paginate(25)
        );
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

        return StockLedgerReportResource::collection($entries);
    }

    /**
     * Assign (or move) this part's stock to a rack/bin location.
     */
    public function updateLocation(UpdatePartStockLocationRequest $request, PartStock $partStock): PartStockResource
    {
        $partStock->update($request->validated());

        return new PartStockResource($partStock->fresh(['part', 'branch', 'supplier', 'location']));
    }

    /**
     * Reverse lookup: which parts are currently stocked at this location.
     */
    public function forLocation(Location $location): AnonymousResourceCollection
    {
        return PartStockResource::collection(
            $location->partStocks()->with('part')->get()
        );
    }
}
