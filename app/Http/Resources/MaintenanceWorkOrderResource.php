<?php

namespace App\Http\Resources;

use App\Models\MaintenanceWorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceWorkOrder
 */
class MaintenanceWorkOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'line_id' => $this->line_id,
            'line_name' => $this->whenLoaded('line', fn () => $this->line?->name),
            'planned_date' => $this->planned_date?->toDateString(),
            'status' => $this->status->value,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            // Works whether the caller used withCount('tasks') (tasks_count
            // present, tasks itself not necessarily loaded) or with('tasks')
            // (the full relation loaded, no separate count column) — every
            // current caller does the latter, but this stays correct either way.
            'task_count' => isset($this->tasks_count) ? $this->tasks_count : $this->whenLoaded('tasks', fn () => $this->tasks->count()),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'created_at' => $this->created_at,
        ];
    }
}
