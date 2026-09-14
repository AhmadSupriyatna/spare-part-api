<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'machine_id' => $this->machine_id,
            'machine_name' => $this->whenLoaded('machine', fn () => $this->machine?->name),
            'line_id' => $this->whenLoaded('machine', fn () => $this->machine?->line_id),
            'line_name' => $this->whenLoaded('machine', fn () => $this->machine?->line?->name),
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
