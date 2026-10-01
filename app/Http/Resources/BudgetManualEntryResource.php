<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetManualEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budget_id,
            'type' => $this->type,
            'line_id' => $this->line_id,
            'line_name' => $this->whenLoaded('line', fn () => $this->line?->name),
            'machine_id' => $this->machine_id,
            'machine_name' => $this->whenLoaded('machine', fn () => $this->machine?->name),
            'description' => $this->description,
            'amount' => $this->amount,
            'occurred_at' => $this->occurred_at?->toDateString(),
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
