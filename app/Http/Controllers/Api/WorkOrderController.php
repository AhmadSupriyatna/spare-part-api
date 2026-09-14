<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Http\Resources\TaskResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\WorkOrder;
use App\Services\TaskService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WorkOrderController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return WorkOrderResource::collection($equipment->workOrders()->with('part')->orderBy('title')->get());
    }

    public function store(StoreWorkOrderRequest $request, Equipment $equipment): WorkOrderResource
    {
        $workOrder = $equipment->workOrders()->create($request->validated());

        return new WorkOrderResource($workOrder);
    }

    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        return new WorkOrderResource($workOrder);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        $workOrder->update($request->validated());

        return new WorkOrderResource($workOrder);
    }

    public function destroy(WorkOrder $workOrder): Response
    {
        $workOrder->delete();

        return response()->noContent();
    }

    /**
     * Manually generate the first/next task occurrence for a recurring work order.
     */
    public function generateTask(WorkOrder $workOrder): TaskResource
    {
        return new TaskResource($this->tasks->generateNextTask($workOrder));
    }

    /**
     * Which work orders (across all equipment) reference this part —
     * surfaces the existing WorkOrder->Part link from the part's side.
     */
    public function forPart(Part $part): AnonymousResourceCollection
    {
        return WorkOrderResource::collection(
            $part->workOrders()->with('equipment')->get()
        );
    }
}
