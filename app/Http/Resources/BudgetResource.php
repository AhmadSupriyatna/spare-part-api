<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'replacement_strategy' => $this->replacement_strategy->value,
            'global_lifetime_percent' => $this->global_lifetime_percent,
            'global_price_increase_percent' => $this->global_price_increase_percent,
            'year' => $this->year,
            'planned_amount' => $this->planned_amount,
            'corrective_amount' => $this->corrective_amount,
            'total_amount' => $this->totalAmount(),
            'items' => BudgetItemResource::collection($this->whenLoaded('items')),
            'manual_entries' => BudgetManualEntryResource::collection($this->whenLoaded('manualEntries')),
            'actual_planned' => $this->when(isset($this->realization), fn () => $this->realization['planned']),
            'actual_unplanned' => $this->when(isset($this->realization), fn () => $this->realization['unplanned']),
            'actual_manual' => $this->when(isset($this->realization), fn () => $this->realization['manual']),
            'actual_total' => $this->when(isset($this->realization), fn () => $this->realization['total']),
            'unpriced_transactions' => $this->when(isset($this->realization), fn () => $this->realization['unpriced_transactions']),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
