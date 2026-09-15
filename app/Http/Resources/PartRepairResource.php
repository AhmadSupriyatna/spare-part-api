<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartRepair
 */
class PartRepairResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_unit_id' => $this->part_unit_id,
            'unit_code' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->unit_code),
            'part_name' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->part?->name),
            'part_installation_id' => $this->part_installation_id,
            'item_master_no' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->part?->item_master_no),
            'equipment_name' => $this->whenLoaded(
                'partInstallation',
                fn () => $this->partInstallation?->equipment?->name
            ),
            'machine_name' => $this->whenLoaded(
                'partInstallation',
                fn () => $this->partInstallation?->equipment?->machine?->name
            ),
            'line_name' => $this->whenLoaded(
                'partInstallation',
                fn () => $this->partInstallation?->equipment?->machine?->line?->name
            ),
            'removed_at' => $this->removed_at,
            'disposition' => $this->disposition,
            'repaired_at' => $this->repaired_at,
            'notes' => $this->notes,
            'reinstalled_installation_id' => $this->reinstalled_installation_id,
            'created_at' => $this->created_at,
        ];
    }
}
