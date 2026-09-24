<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenanceHistoryEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceHistoryResource;
use App\Models\Branch;
use App\Services\MaintenanceHistoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MaintenanceHistoryController extends Controller
{
    public function __construct(private readonly MaintenanceHistoryService $history) {}

    /**
     * Read-only, aggregated Maintenance History feed for one branch — see
     * MaintenanceHistoryService for the read-model/granularity rationale.
     * Never creates, updates, or deletes anything.
     */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'event_type' => ['nullable', Rule::enum(MaintenanceHistoryEventType::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return MaintenanceHistoryResource::collection($this->history->forBranch($branch, $filters));
    }
}
