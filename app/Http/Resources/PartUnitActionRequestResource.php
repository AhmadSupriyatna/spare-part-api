<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartUnitActionRequest
 */
class PartUnitActionRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'part_unit_id' => $this->part_unit_id,
            'unit_code' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->unit_code),
            'part_name' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->part?->name),
            'item_master_no' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->part?->item_master_no),
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->name),
            'machine_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->name),
            'line_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line?->name),
            'requested_by_name' => $this->requested_by_name,
            'notes' => $this->notes,
            'status' => $this->status,
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'review_notes' => $this->review_notes,
            'created_at' => $this->created_at,
        ];
    }
}
