<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for every stock quantity change. Writes an append-only
 * stock_ledger row and updates the part_stocks.quantity_on_hand cache inside
 * one DB transaction with a row lock, so concurrent requests for the same
 * part+branch can't race each other into an inconsistent balance.
 */
class StockMovementService
{
    /**
     * @param  int  $quantityChange  Positive to add stock, negative to remove it.
     *
     * @throws InsufficientStockException if the resulting balance would go below zero.
     */
    /**
     * @param  \Closure(PartStock $locked, int $newBalance): array<string, mixed>|null  $additionalUpdates
     *                                                                                                      Runs inside the same locked transaction as the quantity change, so it can safely read the
     *                                                                                                      pre-update quantity/cost off $locked (e.g. to fold a receiving's cost into a moving average)
     *                                                                                                      and return extra columns to save alongside quantity_on_hand.
     * @param  string|null  $unitCostSnapshot
     *                                         The per-unit cost this specific transaction actually happened at, recorded on the ledger
     *                                         row so later reporting (Budget realization) doesn't have to re-derive it from
     *                                         part_stocks.unit_cost, which keeps moving. Defaults to the part_stock's unit_cost as it
     *                                         stands after $additionalUpdates runs — correct for issue/adjustment/etc, where the
     *                                         transaction doesn't establish its own price. A receiving, which DOES have its own price
     *                                         (the batch total divided by quantity), passes it explicitly rather than relying on this
     *                                         default, since the resulting blended moving average is not what that batch cost.
     */
    public function record(
        PartStock $partStock,
        string $type,
        int $quantityChange,
        ?User $user = null,
        ?string $notes = null,
        ?Model $reference = null,
        ?\Closure $additionalUpdates = null,
        ?string $unitCostSnapshot = null,
    ): StockLedger {
        return DB::transaction(function () use ($partStock, $type, $quantityChange, $user, $notes, $reference, $additionalUpdates, $unitCostSnapshot) {
            /** @var PartStock $locked */
            $locked = PartStock::whereKey($partStock->id)->lockForUpdate()->firstOrFail();

            $newBalance = $locked->quantity_on_hand + $quantityChange;

            if ($newBalance < 0) {
                throw new InsufficientStockException($locked->quantity_on_hand, abs($quantityChange));
            }

            $updates = ['quantity_on_hand' => $newBalance];

            if ($additionalUpdates) {
                $updates = array_merge($updates, $additionalUpdates($locked, $newBalance));
            }

            $locked->update($updates);

            $ledgerEntry = StockLedger::create([
                'part_stock_id' => $locked->id,
                'type' => $type,
                'quantity_change' => $quantityChange,
                'balance_after' => $newBalance,
                'unit_cost' => $unitCostSnapshot ?? $locked->unit_cost,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'notes' => $notes,
                'user_id' => $user?->id,
                'occurred_at' => now(),
            ]);

            return $ledgerEntry;
        });
    }
}
