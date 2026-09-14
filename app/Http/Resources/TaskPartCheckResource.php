<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TaskPartCheck
 */
class TaskPartCheckResource extends JsonResource
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
            'quantity_planned' => $this->quantity_planned,
            'is_replaced' => $this->is_replaced,
            'quantity_used' => $this->quantity_used,
            'reason' => $this->reason,
        ];
    }
}
