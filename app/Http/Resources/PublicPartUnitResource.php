<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The QR-scan landing page's view of a unit — just enough to show the
 * technician what they scanned and where it currently is, without exposing
 * its full internal repair/installation history.
 *
 * @mixin \App\Models\PartUnit
 */
class PublicPartUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $this->currentInstallation();

        return [
            'id' => $this->id,
            'unit_code' => $this->unit_code,
            'status' => $this->status,
            'part_id' => $this->part_id,
            'part_name' => $this->part?->name,
            'item_master_no' => $this->part?->item_master_no,
            'part_image_url' => $this->part?->imageUrl(),
            'current_equipment' => $current ? [
                'id' => $current->equipment_id,
                'name' => $current->equipment?->name,
                'machine_name' => $current->equipment?->machine?->name,
                'line_name' => $current->equipment?->machine?->line?->name,
            ] : null,
        ];
    }
}
