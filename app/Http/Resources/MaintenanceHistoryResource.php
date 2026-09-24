<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string, mixed>
 */
class MaintenanceHistoryResource extends JsonResource
{
    /**
     * MaintenanceHistoryService already produces the exact normalized shape
     * this endpoint returns — nothing to reshape here, this resource exists
     * only so the controller follows the same XxxResource::collection()
     * convention as every other endpoint in this API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
