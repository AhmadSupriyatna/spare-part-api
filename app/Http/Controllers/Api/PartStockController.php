<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\ReceiveStockRequest;
use App\Http\Requests\UpdatePartStockLocationRequest;
use App\Http\Resources\PartStockResource;
use App\Http\Resources\StockLedgerResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function receive(ReceiveStockRequest $request, PartStock $partStock): JsonResponse
    {
        $data = $request->validated();

        $this->stockMovements->record(
            partStock: $partStock,
            type: StockLedger::TYPE_RECEIVING,
            quantityChange: $data['quantity'],
            user: $request->user(),
            notes: $data['notes'] ?? null,
        );

        $updates = array_filter([
            'unit_cost' => $data['unit_cost'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
        ], fn ($value) => $value !== null);

        if ($updates !== []) {
            $partStock->update($updates);
        }

        return (new PartStockResource($partStock->fresh(['part', 'branch', 'supplier', 'location'])))
            ->response()
            ->setStatusCode(200);
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
