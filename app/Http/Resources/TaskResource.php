<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'task_library_id' => $this->task_library_id,
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->name),
            'machine_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->name),
            'line_name' => $this->whenLoaded('equipment', fn () => $this->equipment?->machine?->line?->name),
            'branch_name' => $this->whenLoaded(
                'equipment',
                fn () => $this->equipment?->machine?->line?->branch?->name
            ),
            'assigned_to' => $this->assigned_to,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'cause' => $this->cause,
            'due_date' => $this->due_date,
            'due_runtime_hours' => $this->due_runtime_hours,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'completion_notes' => $this->completion_notes,
            'part_stock_id' => $this->part_stock_id,
            'part_name' => $this->whenLoaded('partStock', fn () => $this->partStock?->part?->name),
            'item_master_no' => $this->whenLoaded('partStock', fn () => $this->partStock?->part?->item_master_no),
            'quantity_used' => $this->quantity_used,
            'part_checks' => TaskPartCheckResource::collection($this->whenLoaded('partChecks')),
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at,
        ];
    }
}
