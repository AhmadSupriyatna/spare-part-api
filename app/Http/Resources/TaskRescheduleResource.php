<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\TaskReschedule
 */
class TaskRescheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'task_title' => $this->whenLoaded('task', fn () => $this->task?->title),
            'equipment_name' => $this->whenLoaded('task', fn () => $this->task?->equipment?->name),
            'previous_due_date' => $this->previous_due_date,
            'new_due_date' => $this->new_due_date,
            'reason' => $this->reason,
            'rescheduled_by_name' => $this->whenLoaded('rescheduledBy', fn () => $this->rescheduledBy?->name),
            'created_at' => $this->created_at,
        ];
    }
}
