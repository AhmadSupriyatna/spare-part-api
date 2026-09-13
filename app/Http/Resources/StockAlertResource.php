<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockAlert
 */
class StockAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_stock_id' => $this->part_stock_id,
            'part_name' => $this->whenLoaded('partStock', fn () => $this->partStock->part?->name),
            'item_master_no' => $this->whenLoaded('partStock', fn () => $this->partStock->part?->item_master_no),
            'level' => $this->level,
            'quantity_on_hand_at_trigger' => $this->quantity_on_hand_at_trigger,
            'is_resolved' => $this->is_resolved,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
