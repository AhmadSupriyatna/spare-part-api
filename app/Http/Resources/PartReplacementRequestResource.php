<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartReplacementRequest
 */
class PartReplacementRequestResource extends JsonResource
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
            'part_image_url' => $this->whenLoaded('part', fn () => $this->part?->imageUrl()),
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->name),
            'machine_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->name),
            'line_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line?->name),
            'branch_name' => $this->whenLoaded(
                'equipment',
                fn () => $this->equipment?->machine?->line?->branch?->name
            ),
            'quantity_used' => $this->quantity_used,
            'requested_by_name' => $this->requested_by_name,
            'reason' => $this->reason,
            'status' => $this->status,
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'reviewed_at' => $this->reviewed_at,
            'review_notes' => $this->review_notes,
            'created_at' => $this->created_at,
        ];
    }
}
