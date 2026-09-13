<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Part
 */
class PartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_master_no' => $this->item_master_no,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'category' => $this->category,
            'price' => $this->price,
            'image_url' => $this->imageUrl(),
            'is_active' => $this->is_active,
            'stocks' => PartStockResource::collection($this->whenLoaded('stocks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
