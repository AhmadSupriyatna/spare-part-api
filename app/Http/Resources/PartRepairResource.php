<?php

namespace App\Http\Resources;

use App\Enums\PartRepairDisposition;
use App\Models\PartRepair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin PartRepair
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
            'percent_used' => $this->whenLoaded('partUnit', fn () => $this->partUnit?->percentUsed()),
            'total_runtime_hours_used' => $this->whenLoaded(
                'partUnit',
                fn () => $this->partUnit?->totalRuntimeHoursUsed()
            ),
            'removed_at' => $this->removed_at,
            'estimated_completion_date' => $this->estimated_completion_date,
            'disposition' => $this->disposition,
            'is_overdue' => $this->disposition === PartRepairDisposition::InRepair
                && $this->estimated_completion_date !== null
                && $this->estimated_completion_date->lt(Carbon::today()),
            'repaired_at' => $this->repaired_at,
            'repair_cost' => $this->repair_cost,
            'notes' => $this->notes,
            'reinstalled_installation_id' => $this->reinstalled_installation_id,
            'created_at' => $this->created_at,
        ];
    }
}
