<?php

namespace App\Http\Resources;

use App\Models\PartReplacementRequest;
use App\Models\StockLedger;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockLedger
 *
 * Branch-wide, cross-part ledger for the "Kelola Stok" ledger tab/report —
 * unlike StockLedgerResource (one part_stock's own drill-down history),
 * this resolves which line/equipment a movement is traceable to (when its
 * polymorphic `reference` is a Task or PartReplacementRequest) and a
 * human "jalur" label, so the report can be filtered by line and shows
 * where each movement actually came from.
 */
class StockLedgerReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $equipment = $this->reference?->equipment ?? null;

        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at,
            'part_id' => $this->partStock->part_id,
            'part_name' => $this->partStock->part->name,
            'item_master_no' => $this->partStock->part->item_master_no,
            'replacement_strategy' => $this->partStock->part->replacement_strategy->value,
            'type' => $this->type,
            'channel' => $this->channelLabel(),
            'quantity_change' => $this->quantity_change,
            'balance_after' => $this->balance_after,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->unit_cost !== null ? bcmul((string) $this->unit_cost, (string) abs($this->quantity_change), 2) : null,
            'notes' => $this->notes,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'line_id' => $equipment?->machine?->line_id,
            'line_name' => $equipment?->machine?->line?->name,
            'equipment_name' => $equipment?->name,
        ];
    }

    private function channelLabel(): string
    {
        return match (true) {
            $this->reference_type === Task::class => 'WO PM',
            $this->reference_type === PartReplacementRequest::class => 'Breakdown',
            $this->type === StockLedger::TYPE_RECEIVING => 'Penerimaan',
            $this->type === StockLedger::TYPE_ADJUSTMENT => 'Penyesuaian Manual',
            $this->type === StockLedger::TYPE_RETURN => 'Retur',
            $this->type === StockLedger::TYPE_TRANSFER_IN => 'Transfer Masuk',
            $this->type === StockLedger::TYPE_TRANSFER_OUT => 'Transfer Keluar',
            $this->type === StockLedger::TYPE_CORRECTION => 'Koreksi',
            default => ucfirst((string) $this->type),
        };
    }
}
