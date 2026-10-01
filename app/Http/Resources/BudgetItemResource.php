<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part_id' => $this->part_id,
            'part_name' => $this->whenLoaded('part', fn () => $this->part?->name),
            'item_master_no' => $this->whenLoaded('part', fn () => $this->part?->item_master_no),
            'estimated_failure_count_per_year' => $this->estimated_failure_count_per_year,
            'replacement_probability_percent' => $this->replacement_probability_percent,
            'unit_cost' => $this->unit_cost,
            'estimated_cost' => $this->estimated_cost,
            // Set by BudgetController via BudgetForecastService::lineBreakdown()/
            // machineBreakdown() before wrapping — live splits computed fresh
            // from current installations, never stored, so they can't go
            // stale as parts move between lines/machines after the budget is
            // saved.
            'by_line' => $this->when(isset($this->by_line), fn () => $this->by_line),
            'by_machine' => $this->when(isset($this->by_machine), fn () => $this->by_machine),
        ];
    }
}
