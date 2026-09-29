<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Http\Resources\PartInstallationResource;
use App\Http\Resources\TaskResource;
use App\Models\Machine;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\Task;
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
 * EquipmentResource/PartResource/MachineResource as-is.
 */
class PublicMachineMonitoringController extends Controller
{
    public function show(Machine $machine): JsonResponse
    {
        $machine->load(['line.branch', 'branch']);

        $equipmentIds = $machine->equipment()->pluck('id');

        $tasks = Task::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress])
            ->with(['equipment', 'assignee'])
            ->orderBy('due_date')
            ->limit(20)
            ->get();

        $installations = PartInstallation::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereNull('removed_at')
            ->with(['equipment', 'part', 'partUnit'])
            ->get();

        $months = collect(range(0, 11))->map(fn ($i) => now()->subMonths($i)->format('Y-m'))->reverse()->values();
        $failuresByMonth = PartReplacementRequest::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->whereIn('event_type', ['failure', 'breakdown'])
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['created_at'])
            ->groupBy(fn (PartReplacementRequest $request) => $request->created_at->format('Y-m'))
            ->map(fn ($rows) => $rows->count());

        $monthlyFailureTrend = $months->map(fn ($month) => [
            'month' => $month,
            'count' => (int) ($failuresByMonth[$month] ?? 0),
        ])->values();

        $lifetimeHours = $installations
            ->map(fn (PartInstallation $installation) => $installation->ageInRuntimeHours())
            ->filter(fn ($hours) => $hours !== null);

        return response()->json(['data' => [
            'machine' => new MachineResource($machine),
            'equipment_count' => $equipmentIds->count(),
            'upcoming_tasks' => TaskResource::collection($tasks),
            'overdue_task_count' => $tasks->filter(fn (Task $task) => $task->isOverdue())->count(),
            'installations' => PartInstallationResource::collection($installations),
            'monthly_failure_trend' => $monthlyFailureTrend,
            'average_lifetime_hours' => $lifetimeHours->isEmpty() ? null : (int) round($lifetimeHours->avg()),
        ]]);
    }
}
