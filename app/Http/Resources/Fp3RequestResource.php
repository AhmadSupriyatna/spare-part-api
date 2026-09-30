<?php

namespace App\Http\Resources;

use App\Models\Fp3Request;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Fp3Request
 */
class Fp3RequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'code' => $this->code,
            'requester_name' => $this->requester_name,
            'department' => $this->department,
            'description' => $this->description,
            'request_photo_url' => $this->requestPhotoUrl(),
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'due_date' => $this->due_date,
            'received_at' => $this->received_at,
            'received_by' => $this->received_by,
            'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'work_description' => $this->work_description,
            'completed_at' => $this->completed_at,
            'executor_names' => $this->executor_names,
            'area_condition_after' => $this->area_condition_after,
            'completion_photo_url' => $this->completionPhotoUrl(),
            'disposition' => $this->disposition,
            'disposition_note' => $this->disposition_note,
            'part_usages' => Fp3PartUsageResource::collection($this->whenLoaded('partUsages')),
            'created_at' => $this->created_at,
        ];
    }
}
