<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PartInstallation
 */
class PartInstallationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workOrder = $this->relevantWorkOrder();
        $ageInDays = $this->ageInDays();
        $ageInRuntimeHours = $this->ageInRuntimeHours();

        $percentUsed = null;
        if ($workOrder?->interval_days) {
            $percentUsed = min(100, (int) round(($ageInDays / $workOrder->interval_days) * 100));
        } elseif ($workOrder?->interval_hours && $ageInRuntimeHours !== null) {
            $percentUsed = min(100, (int) round(($ageInRuntimeHours / $workOrder->interval_hours) * 100));
        }

        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->name),
            'machine_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->name),
            'line_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line?->name),
            'part_id' => $this->part_id,
            'part_name' => $this->whenLoaded('part', fn () => $this->part?->name),
            'item_master_no' => $this->whenLoaded('part', fn () => $this->part?->item_master_no),
            'installed_at' => $this->installed_at,
            'installed_at_runtime_hours' => $this->installed_at_runtime_hours,
            'removed_at' => $this->removed_at,
            'removed_at_runtime_hours' => $this->removed_at_runtime_hours,
            'installed_by_name' => $this->whenLoaded('installedBy', fn () => $this->installedBy?->name),
            'notes' => $this->notes,
            'is_active' => $this->isActive(),
            'age_in_days' => $ageInDays,
            'age_in_runtime_hours' => $ageInRuntimeHours,
            'expected_interval_days' => $workOrder?->interval_days,
            'expected_interval_hours' => $workOrder?->interval_hours,
            'percent_used' => $percentUsed,
        ];
    }
}
