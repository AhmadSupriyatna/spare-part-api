<?php

namespace App\Services;

use App\Enums\ReorderStatus;
use App\Enums\StockAlertLevel;
use App\Models\PartStock;
use App\Models\ReorderRequest;
use App\Models\StockAlert;

/**
 * Evaluates a part_stock's quantity against its thresholds after every stock
 * movement, keeping stock_alerts and reorder_requests in sync automatically:
 * an alert opens (and a reorder request is raised) once stock drops to the
 * reorder point or below, and both resolve on their own once stock is
 * replenished back above it.
 */
class StockAlertService
{
    public function evaluate(PartStock $partStock): void
    {
        $level = match (true) {
            $partStock->quantity_on_hand <= $partStock->minimum_stock => StockAlertLevel::Critical,
            $partStock->quantity_on_hand <= $partStock->reorder_point => StockAlertLevel::Low,
            default => null,
        };

        $openAlert = $partStock->alerts()->where('is_resolved', false)->first();

        if ($level === null) {
            if ($openAlert) {
                $openAlert->update(['is_resolved' => true, 'resolved_at' => now()]);
            }

            $this->completeOpenReorderRequests($partStock);

            return;
        }

        if ($openAlert) {
            if ($openAlert->level !== $level) {
                $openAlert->update([
                    'level' => $level,
                    'quantity_on_hand_at_trigger' => $partStock->quantity_on_hand,
                ]);
            }
        } else {
            StockAlert::create([
                'part_stock_id' => $partStock->id,
                'level' => $level,
                'quantity_on_hand_at_trigger' => $partStock->quantity_on_hand,
            ]);
        }

        $this->ensureReorderRequest($partStock);
    }

    private function ensureReorderRequest(PartStock $partStock): void
    {
        $hasOpenRequest = $partStock->reorderRequests()
            ->whereNotIn('status', [ReorderStatus::Completed, ReorderStatus::Cancelled])
            ->exists();

        if ($hasOpenRequest) {
            return;
        }

        ReorderRequest::create([
            'part_stock_id' => $partStock->id,
            'supplier_id' => $partStock->supplier_id,
            'quantity_requested' => $partStock->reorder_quantity,
            'status' => ReorderStatus::Pending,
        ]);
    }

    private function completeOpenReorderRequests(PartStock $partStock): void
    {
        $partStock->reorderRequests()
            ->whereNotIn('status', [ReorderStatus::Completed, ReorderStatus::Cancelled])
            ->update(['status' => ReorderStatus::Completed]);
    }
}
