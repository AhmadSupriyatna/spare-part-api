<?php

namespace App\Http\Resources;

use App\Models\ProductionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionLine
 */
class LineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'code' => $this->code,
            'name' => $this->name,
            'runtime_hours' => $this->runtime_hours,
            'avg_weekly_operating_hours' => $this->avg_weekly_operating_hours,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
