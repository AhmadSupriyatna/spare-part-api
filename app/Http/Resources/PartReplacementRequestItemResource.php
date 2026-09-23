<?php

namespace App\Http\Resources;

use App\Models\PartReplacementRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PartReplacementRequestItem
 */
class PartReplacementRequestItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_installation_id' => $this->old_installation_id,
            'old_slot_label' => $this->whenLoaded('oldInstallation', fn () => $this->oldInstallation?->slot_label),
            'new_installation_id' => $this->new_installation_id,
            'new_slot_label' => $this->whenLoaded('newInstallation', fn () => $this->newInstallation?->slot_label),
        ];
    }
}
