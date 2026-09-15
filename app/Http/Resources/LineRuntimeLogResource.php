<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LineRuntimeLog
 */
class LineRuntimeLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_id' => $this->line_id,
            'previous_hours' => $this->previous_hours,
            'new_hours' => $this->new_hours,
            'hours_added' => $this->hours_added,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
