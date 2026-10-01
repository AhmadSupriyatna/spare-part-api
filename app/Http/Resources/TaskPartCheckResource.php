<?php

namespace App\Http\Resources;

use App\Models\TaskPartCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskPartCheck
 */
class TaskPartCheckResource extends JsonResource
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
            'quantity_planned' => $this->quantity_planned,
            'is_replaced' => $this->is_replaced,
            'quantity_used' => $this->quantity_used,
            'reason' => $this->reason,
            'part_installation_id' => $this->part_installation_id,
            // Only ever set for a row that was actually replaced (see
            // TaskService::completeChecklist()) — a "not replaced" row
            // never recorded which physical unit/address it was, so this
            // stays null there rather than guessing.
            'slot_label' => $this->whenLoaded('partInstallation', fn () => $this->partInstallation?->slot_label),
        ];
    }
}
