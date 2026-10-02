<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStockLedgerReceivingRequest;
use App\Http\Resources\PartStockResource;
use App\Models\PartStock;
use App\Models\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Editing/deleting a Stock In (receiving) ledger row — everything else in
 * stock_ledger stays append-only (see StockLedger's own docblock). Allowed
 * only for the single MOST RECENT receiving entry of a part_stock, so there
 * is never anything downstream to recalculate: reversing this one entry's
 * effect only ever means restoring PartStock to the state it was in right
 * before this entry was originally written, which replayPartStockState()
 * recovers by replaying every OTHER entry for that part_stock (there being
 * none after the one being edited/deleted, by the guard below).
 */
class StockLedgerController extends Controller
{
    public function updateReceiving(UpdateStockLedgerReceivingRequest $request, StockLedger $stockLedger): PartStockResource
    {
        $this->assertEditableReceiving($stockLedger);
        $data = $request->validated();
        $part = $stockLedger->partStock->part;

        if ($part->has_passport && (int) $data['quantity'] !== $stockLedger->quantity_change) {
            abort(422, 'Part ber-Passport — jumlah tidak bisa diubah di sini karena setiap unit fisiknya sudah tercatat tersendiri (ada QR-nya). Hubungi Superadmin untuk koreksi manual.');
        }

        DB::transaction(function () use ($stockLedger, $data) {
            $locked = PartStock::whereKey($stockLedger->part_stock_id)->lockForUpdate()->firstOrFail();
            [$qtyBefore, $unitCostBefore] = $this->replayPartStockState($locked, $stockLedger);

            $newBalance = $qtyBefore + $data['quantity'];
            $costBasis = bcadd(bcmul($unitCostBefore, (string) $qtyBefore, 2), (string) $data['total_price'], 2);
            $newUnitCost = $newBalance > 0 ? bcdiv($costBasis, (string) $newBalance, 2) : $unitCostBefore;

            $locked->update([
                'quantity_on_hand' => $newBalance,
                'unit_cost' => $newUnitCost,
                'supplier_id' => $data['supplier_id'] ?? $locked->supplier_id,
            ]);

            $stockLedger->update([
                'quantity_change' => $data['quantity'],
                'balance_after' => $newBalance,
                'unit_cost' => round($data['total_price'] / $data['quantity'], 2),
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? $stockLedger->occurred_at,
            ]);
        });

        $fresh = $stockLedger->partStock->fresh(['part', 'branch', 'supplier', 'location']);

        return new PartStockResource($fresh);
    }

    public function destroyReceiving(StockLedger $stockLedger): JsonResponse
    {
        $this->assertEditableReceiving($stockLedger);
        $part = $stockLedger->partStock->part;

        abort_if(
            $part->has_passport,
            422,
            'Part ber-Passport tidak bisa dihapus transaksi penerimaannya di sini karena unit fisiknya sudah tercatat tersendiri (ada QR-nya). Hubungi Superadmin untuk koreksi manual.'
        );

        DB::transaction(function () use ($stockLedger) {
            $locked = PartStock::whereKey($stockLedger->part_stock_id)->lockForUpdate()->firstOrFail();
            [$qtyBefore, $unitCostBefore] = $this->replayPartStockState($locked, $stockLedger);

            $locked->update(['quantity_on_hand' => $qtyBefore, 'unit_cost' => $unitCostBefore]);
            $stockLedger->delete();
        });

        return response()->json(['message' => 'Transaksi penerimaan dihapus.']);
    }

    private function assertEditableReceiving(StockLedger $stockLedger): void
    {
        abort_unless(
            $stockLedger->type === StockLedger::TYPE_RECEIVING,
            422,
            'Hanya transaksi penerimaan (Stock In) yang bisa diubah/dihapus.'
        );

        $latestId = StockLedger::where('part_stock_id', $stockLedger->part_stock_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('id');

        abort_unless(
            $latestId === $stockLedger->id,
            422,
            'Hanya transaksi penerimaan TERAKHIR untuk part ini yang bisa diubah/dihapus — sudah ada pergerakan stok setelahnya.'
        );
    }

    /**
     * Replays every OTHER ledger entry for this part_stock (chronological,
     * excluding $target) to recover the exact quantity_on_hand/unit_cost
     * that existed immediately before $target was originally recorded.
     * Correct regardless of history length because $target is guaranteed
     * (by assertEditableReceiving()) to be the LAST entry — nothing after
     * it needs to be touched.
     *
     * @return array{0: int, 1: string}
     */
    private function replayPartStockState(PartStock $partStock, StockLedger $target): array
    {
        $entries = StockLedger::where('part_stock_id', $partStock->id)
            ->where('id', '!=', $target->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['type', 'quantity_change', 'unit_cost']);

        $qty = 0;
        $unitCost = '0.00';

        foreach ($entries as $entry) {
            if ($entry->type === StockLedger::TYPE_RECEIVING) {
                $newQty = $qty + $entry->quantity_change;
                $costBasis = bcadd(bcmul($unitCost, (string) $qty, 2), bcmul((string) $entry->unit_cost, (string) $entry->quantity_change, 2), 2);
                $unitCost = $newQty > 0 ? bcdiv($costBasis, (string) $newQty, 2) : $unitCost;
                $qty = $newQty;
            } else {
                $qty += $entry->quantity_change;
            }
        }

        return [$qty, $unitCost];
    }
}
