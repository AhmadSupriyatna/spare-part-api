<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartSupplier
 */
class PartSupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_id' => $this->part_id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'branch_id' => $this->whenLoaded('supplier', fn () => $this->supplier?->branch_id),
            'branch_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->branch?->name),
            'price' => $this->price,
            'lead_time_days' => $this->lead_time_days,
            'is_preferred' => $this->is_preferred,
            'notes' => $this->notes,
        ];
    }
}
