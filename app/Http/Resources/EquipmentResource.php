<?php

namespace App\Http\Resources;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'machine_id' => $this->machine_id,
            'machine_name' => $this->whenLoaded('machine', fn () => $this->machine?->name),
            'line_id' => $this->whenLoaded('machine', fn () => $this->machine?->line_id),
            'line_name' => $this->whenLoaded('machine', fn () => $this->machine?->line?->name),
            'code' => $this->code,
            'hierarchical_code' => $this->whenLoaded('machine', fn () => $this->hierarchicalCode()),
            'name' => $this->name,
            'category' => $this->category,
            'is_active' => $this->is_active,
            // Only set when the caller eager-loaded it via withCount() scoped
            // to one specific part (see
            // PublicBreakdownController::equipmentForPartInBranch()) — absent
            // for every other, part-agnostic use of this resource.
            'active_installation_count' => $this->when(
                isset($this->active_installation_count),
                fn () => (int) $this->active_installation_count
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
