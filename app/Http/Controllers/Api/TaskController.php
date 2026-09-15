<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PartStockNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTaskRequest;
use App\Http\Requests\CompleteTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * Every PM task ever scheduled, across the branch — whether it came from
     * a Task Library recipe (task_library_id set, even if its checklist is
     * empty — a valid "inspection only, nothing to replace" recipe) or a
     * lifetime-worn-part flag (no task_library_id, but always leaves exactly
     * one task_part_checks row). Feeds the "WO Ledger" tab and the PM
     * calendar.
     */
    public function pmSchedule(Branch $branch): AnonymousResourceCollection
    {
        return TaskResource::collection(
            Task::where(fn ($q) => $q->whereNotNull('task_library_id')->orWhereHas('partChecks'))
                ->whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
                ->with(['equipment.machine.line.branch', 'assignee', 'taskLibrary', 'partChecks.part'])
                ->orderByDesc('due_date')
                ->get()
        );
    }

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return TaskResource::collection(
            $equipment->tasks()
                ->with(['equipment.machine.line.branch', 'assignee', 'partStock.part', 'partChecks.part'])
                ->latest('due_date')
                ->get()
        );
    }

    /**
     * Tasks assigned to the currently authenticated user (for a teknisi's own worklist).
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        return TaskResource::collection(
            Task::where('assigned_to', $request->user()->id)
                ->with(['equipment.machine.line.branch', 'assignee', 'partStock.part', 'partChecks.part'])
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
        return new TaskResource($task->load([
            'equipment.machine.line.branch',
            'assignee',
            'workOrder',
            'taskLibrary',
            'partStock.part',
            'partChecks.part',
        ]));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task);
    }

    public function start(Request $request, Task $task): TaskResource
    {
        $this->ensureAssignee($request, $task);

        return new TaskResource($this->tasks->start($task));
    }

    public function complete(CompleteTaskRequest $request, Task $task): JsonResponse|TaskResource
    {
        $this->ensureAssignee($request, $task);

        $data = $request->validated();

        try {
            $updated = $this->tasks->complete(
                task: $task,
                user: $request->user(),
                notes: $data['notes'] ?? null,
                partStockId: $data['part_stock_id'] ?? null,
                quantityUsed: $data['quantity_used'] ?? null,
                checks: $data['checks'] ?? null,
            );
        } catch (PartStockNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TaskResource($updated->load(['partStock.part', 'partChecks.part']));
    }

    public function cancel(CancelTaskRequest $request, Task $task): TaskResource
    {
        $this->ensureAssignee($request, $task);

        return new TaskResource(
            $this->tasks->cancel($task, $request->validated()['notes'] ?? null)
        );
    }

    /**
     * Only the technician/engineer a task is actually assigned to may work
     * it — closes a gap where any authenticated user could start/complete/
     * cancel any task regardless of who it was handed to.
     */
    private function ensureAssignee(Request $request, Task $task): void
    {
        abort_unless(
            $task->assigned_to === $request->user()->id,
            403,
            'Tugas ini tidak ditugaskan untuk Anda.'
        );
    }

    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
