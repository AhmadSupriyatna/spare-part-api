<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartStock
 */
class PartStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_id' => $this->part_id,
            'part_name' => $this->whenLoaded('part', fn () => $this->part?->name),
            'item_master_no' => $this->whenLoaded('part', fn () => $this->part?->item_master_no),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch->name),
            'supplier_id' => $this->supplier_id,
            'location_id' => $this->location_id,
            'location_code' => $this->whenLoaded('location', fn () => $this->location?->code),
            'minimum_stock' => $this->minimum_stock,
            'reorder_point' => $this->reorder_point,
            'reorder_quantity' => $this->reorder_quantity,
            'unit_cost' => $this->unit_cost,
            'quantity_on_hand' => $this->quantity_on_hand,
            'is_below_reorder_point' => $this->isBelowReorderPoint(),
            'is_critical' => $this->isCritical(),
        ];
    }
}
