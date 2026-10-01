<?php

namespace App\Http\Resources;

use App\Models\LineKwhLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LineKwhLog
 */
class LineKwhLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_id' => $this->line_id,
            'week_start_date' => $this->week_start_date,
            'mode' => $this->mode,
            'previous_reading' => $this->previous_reading,
            'new_reading' => $this->new_reading,
            'kwh_used' => $this->kwh_used,
            'recorded_by_name' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
