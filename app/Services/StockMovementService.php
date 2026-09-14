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
    public function __construct(private readonly StockAlertService $alerts) {}

    /**
     * @param  int  $quantityChange  Positive to add stock, negative to remove it.
     *
     * @throws InsufficientStockException if the resulting balance would go below zero.
     */
    /**
     * @param  \Closure(PartStock $locked, int $newBalance): array<string, mixed>|null  $additionalUpdates
     *         Runs inside the same locked transaction as the quantity change, so it can safely read the
     *         pre-update quantity/cost off $locked (e.g. to fold a receiving's cost into a moving average)
     *         and return extra columns to save alongside quantity_on_hand.
     */
    public function record(
        PartStock $partStock,
        string $type,
        int $quantityChange,
        ?User $user = null,
        ?string $notes = null,
        ?Model $reference = null,
        ?\Closure $additionalUpdates = null,
    ): StockLedger {
        return DB::transaction(function () use ($partStock, $type, $quantityChange, $user, $notes, $reference, $additionalUpdates) {
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
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'notes' => $notes,
                'user_id' => $user?->id,
                'occurred_at' => now(),
            ]);

            $this->alerts->evaluate($locked);

            return $ledgerEntry;
        });
    }
}
