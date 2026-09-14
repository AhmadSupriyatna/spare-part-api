<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WorkOrder
 */
class WorkOrderResource extends JsonResource
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
            'part_id' => $this->part_id,
            'part_name' => $this->whenLoaded('part', fn () => $this->part?->name),
            'title' => $this->title,
            'description' => $this->description,
            'schedule_type' => $this->schedule_type,
            'interval_days' => $this->interval_days,
            'interval_hours' => $this->interval_hours,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
