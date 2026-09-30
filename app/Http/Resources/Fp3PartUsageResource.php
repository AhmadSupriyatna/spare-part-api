<?php

namespace App\Http\Resources;

use App\Models\Fp3PartUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Fp3PartUsage
 */
class Fp3PartUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_id' => $this->part_id,
            'part_name' => $this->part_id ? $this->whenLoaded('part', fn () => $this->part?->name) : $this->part_name_manual,
            'item_master_no' => $this->whenLoaded('part', fn () => $this->part?->item_master_no),
            'is_registered' => $this->part_id !== null,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
