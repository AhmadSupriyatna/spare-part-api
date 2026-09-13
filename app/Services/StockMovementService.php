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
    public function record(
        PartStock $partStock,
        string $type,
        int $quantityChange,
        ?User $user = null,
        ?string $notes = null,
        ?Model $reference = null,
    ): StockLedger {
        return DB::transaction(function () use ($partStock, $type, $quantityChange, $user, $notes, $reference) {
            /** @var PartStock $locked */
            $locked = PartStock::whereKey($partStock->id)->lockForUpdate()->firstOrFail();

            $newBalance = $locked->quantity_on_hand + $quantityChange;

            if ($newBalance < 0) {
                throw new InsufficientStockException($locked->quantity_on_hand, abs($quantityChange));
            }

            $locked->update(['quantity_on_hand' => $newBalance]);

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
