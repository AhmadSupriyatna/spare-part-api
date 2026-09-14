<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartUnit
 */
class PartUnitResource extends JsonResource
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
            'unit_code' => $this->unit_code,
            'status' => $this->status,
            'total_runtime_hours_used' => $this->totalRuntimeHoursUsed(),
            'estimated_lifetime_hours' => $this->whenLoaded('part', fn () => $this->part?->estimated_lifetime_hours),
            'percent_used' => $this->percentUsed(),
            'install_count' => $this->installations()->count(),
            'installations' => PartInstallationResource::collection($this->whenLoaded('installations')),
            'repairs' => PartRepairResource::collection($this->whenLoaded('repairs')),
            'created_at' => $this->created_at,
        ];
    }
}
