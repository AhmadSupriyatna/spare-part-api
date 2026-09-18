<?php

namespace App\Http\Resources;

use App\Models\Rack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rack
 */
class RackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'label' => $this->label,
            'levels' => RackLevelResource::collection($this->whenLoaded('levels')),
        ];
    }
}
