<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Http\Resources\PartInstallationResource;
use App\Http\Resources\TaskResource;
use App\Models\Machine;
use App\Services\MachineSummaryService;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated "Monitoring Life Time Mesin" — scanning a Machine's
 * printed QR (/machines/scan/:machineId) lands here with no login, same
 * shop-floor-friendly access level as PublicBreakdownController. Read-only:
 * nothing here writes anything.
 *
 * Deliberately reuses the same Resources the authenticated app uses
 * (MachineResource, TaskResource, PartInstallationResource) rather than a
 * separate cut-down shape — none of them expose cost/price data, matching
 * the precedent already set by PublicBreakdownController reusing
 * EquipmentResource/PartResource/MachineResource as-is. The cost-bearing
 * fields (installed_part_value, per-month/per-part cost) are never computed
 * here at all — see MachineSummaryService's `$includeCost = false`.
 */
class PublicMachineMonitoringController extends Controller
{
    public function __construct(private MachineSummaryService $summary) {}

    public function show(Machine $machine): JsonResponse
    {
        $machine->load(['line.branch', 'branch']);

        $data = $this->summary->build($machine, includeCost: false);

        return response()->json(['data' => [
            'machine' => new MachineResource($machine),
            'equipment_count' => $data['equipment_count'],
            'upcoming_tasks' => TaskResource::collection($data['upcoming_tasks']),
            'overdue_task_count' => $data['overdue_task_count'],
            'installations' => PartInstallationResource::collection($data['installations']),
            'monthly_failure_trend' => $data['monthly_failure_trend'],
            'average_lifetime_hours' => $data['average_lifetime_hours'],
            'monthly_consumption_trend' => $data['monthly_consumption_trend'],
            'top_parts_consumed' => $data['top_parts_consumed'],
            'historical_part_lifetime' => $data['historical_part_lifetime'],
        ]]);
    }
}
