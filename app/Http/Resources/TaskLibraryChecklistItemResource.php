<?php

namespace App\Http\Resources;

use App\Models\TaskLibraryChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskLibraryChecklistItem
 */
class TaskLibraryChecklistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'order' => $this->order,
            'parts' => TaskLibraryPartResource::collection($this->whenLoaded('parts')),
        ];
    }
}
