<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'rack_level_id' => $this->rack_level_id,
            'bin_number' => $this->bin_number,
            'code' => $this->code,
            'rack_label' => $this->whenLoaded('rackLevel', fn () => $this->rackLevel?->rack?->label),
            'level_number' => $this->whenLoaded('rackLevel', fn () => $this->rackLevel?->level_number),
            'description' => $this->description,
            'is_active' => $this->is_active,
            'part_stocks_count' => $this->whenCounted('partStocks'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
