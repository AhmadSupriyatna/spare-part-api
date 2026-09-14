<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TaskLibrary
 */
class TaskLibraryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->name),
            'machine_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->name),
            'line_id' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line_id),
            'line_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line?->name),
            'title' => $this->title,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'parts' => TaskLibraryPartResource::collection($this->whenLoaded('parts')),
            'created_at' => $this->created_at,
        ];
    }
}
