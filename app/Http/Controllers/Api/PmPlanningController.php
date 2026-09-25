<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TaskAlreadyInWorkOrderException;
use App\Exceptions\TaskNotEligibleForPlanningException;
use App\Exceptions\WorkOrderCannotTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceWorkOrderRequest;
use App\Http\Resources\MaintenanceWorkOrderResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\MaintenanceWorkOrder;
use App\Services\PmPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Phase 3B Step 4 — the PM planning layer: grouping already-generated Tasks
 * into a MaintenanceWorkOrder. Task Library / Part Lifetime / ad-hoc
 * scheduling (PmSchedulingService, PartLifetimeService) are completely
 * unchanged by this — they still create one Task at a time, NEED_PLANNING
 * by default; this controller is the only thing that groups them.
 */
class PmPlanningController extends Controller
{
    public function __construct(private readonly PmPlanningService $planning) {}

    /**
     * Every ungrouped, not-yet-started Task in the branch — the planning
     * workspace's source list.
     */
    public function needingPlanning(Branch $branch): AnonymousResourceCollection
    {
        return TaskResource::collection($this->planning->needingPlanning($branch->id));
    }

    /**
     * Every MaintenanceWorkOrder in the branch, with its Tasks — so the
     * planner can see what's already grouped (and avoid double-assigning a
     * Task) alongside what still needs planning.
     */
    public function indexWorkOrders(Branch $branch): AnonymousResourceCollection
    {
        return MaintenanceWorkOrderResource::collection(
            $branch->maintenanceWorkOrders()
                ->withCount('tasks')
                ->with(['line', 'creator', 'tasks.equipment.machine.line', 'tasks.assignee', 'tasks.taskLibrary'])
                ->latest()
                ->get()
        );
    }

    public function store(StoreMaintenanceWorkOrderRequest $request, Branch $branch): JsonResponse|MaintenanceWorkOrderResource
    {
        try {
            $workOrder = $this->planning->createFromTasks(
                $branch->id,
                $request->validated()['task_ids'],
                $request->user()?->id,
            );
        } catch (TaskAlreadyInWorkOrderException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (TaskNotEligibleForPlanningException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new MaintenanceWorkOrderResource($workOrder))->response()->setStatusCode(201);
    }

    public function show(MaintenanceWorkOrder $workOrder): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource(
            $workOrder->load(['line', 'creator', 'tasks.equipment.machine.line', 'tasks.assignee', 'tasks.taskLibrary'])
        );
    }

    public function markReady(MaintenanceWorkOrder $workOrder): JsonResponse|MaintenanceWorkOrderResource
    {
        try {
            $workOrder->markReady();
        } catch (WorkOrderCannotTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new MaintenanceWorkOrderResource($workOrder->fresh(['line', 'creator', 'tasks']));
    }
}
