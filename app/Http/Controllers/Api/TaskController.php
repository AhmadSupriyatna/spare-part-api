<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTaskRequest;
use App\Http\Requests\CompleteTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Equipment;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return TaskResource::collection(
            $equipment->tasks()->with('assignee')->latest('due_date')->get()
        );
    }

    /**
     * Tasks assigned to the currently authenticated user (for a teknisi's own worklist).
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return TaskResource::collection(
            Task::where('assigned_to', $request->user()->id)
                ->with(['equipment', 'assignee'])
                ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
                ->orderBy('due_date')
                ->get()
        );
    }

    /**
     * Create an ad-hoc task (no work order) — e.g. a reactive/unscheduled
     * repair triggered by a breakdown rather than a preventive schedule.
     */
    public function store(StoreTaskRequest $request, Equipment $equipment): TaskResource
    {
        $task = $equipment->tasks()->create($request->validated());

        return new TaskResource($task);
    }

    public function show(Task $task): TaskResource
    {
        return new TaskResource($task->load(['equipment', 'assignee', 'workOrder', 'partStock']));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task);
    }

    public function start(Task $task): TaskResource
    {
        return new TaskResource($this->tasks->start($task));
    }

    public function complete(CompleteTaskRequest $request, Task $task): TaskResource
    {
        return new TaskResource(
            $this->tasks->complete($task, $request->user(), $request->validated()['notes'] ?? null)
        );
    }

    public function cancel(CancelTaskRequest $request, Task $task): TaskResource
    {
        return new TaskResource(
            $this->tasks->cancel($task, $request->validated()['notes'] ?? null)
        );
    }

    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
