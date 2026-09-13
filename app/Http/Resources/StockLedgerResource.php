<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StockLedger
 */
class StockLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'quantity_change' => $this->quantity_change,
            'balance_after' => $this->balance_after,
            'notes' => $this->notes,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),
            'occurred_at' => $this->occurred_at,
        ];
    }
}
