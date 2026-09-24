<?php

namespace App\Http\Resources;

use App\Models\MaintenanceDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceDecision
 */
class MaintenanceDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'finding_id' => $this->finding_id,
            'decision' => $this->decision->value,
            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at,
            'notes' => $this->notes,
            'resulting_task_id' => $this->resulting_task_id,
            'resulting_task_title' => $this->whenLoaded('resultingTask', fn () => $this->resultingTask?->title),
            'resulting_replacement_request_id' => $this->resulting_replacement_request_id,
            'resulting_replacement_request_status' => $this->whenLoaded(
                'resultingReplacementRequest',
                fn () => $this->resultingReplacementRequest?->status->value
            ),
        ];
    }
}
