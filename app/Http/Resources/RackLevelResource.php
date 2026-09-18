<?php

namespace App\Http\Resources;

use App\Models\RackLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RackLevel
 */
class RackLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rack_id' => $this->rack_id,
            'level_number' => $this->level_number,
            'code' => $this->code(),
            'locations' => LocationResource::collection($this->whenLoaded('locations')),
        ];
    }
}
