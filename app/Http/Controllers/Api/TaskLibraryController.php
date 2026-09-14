<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleTaskLibraryRequest;
use App\Http\Requests\StoreTaskLibraryRequest;
use App\Http\Requests\UpdateTaskLibraryRequest;
use App\Http\Resources\TaskLibraryResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\TaskLibrary;
use App\Services\PmSchedulingService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TaskLibraryController extends Controller
{
    public function __construct(private readonly PmSchedulingService $scheduling) {}

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return TaskLibraryResource::collection(
            $equipment->taskLibraries()->with(['equipment.machine.line', 'parts.part'])->get()
        );
    }

    /**
     * Every library entry across the branch — the PM calendar's "pick a
     * recipe to schedule" list needs this without knowing the equipment id
     * up front.
     */
    public function indexForBranch(Branch $branch): AnonymousResourceCollection
    {
        return TaskLibraryResource::collection(
            TaskLibrary::whereHas('equipment.machine.line', fn ($q) => $q->where('branch_id', $branch->id))
                ->where('is_active', true)
                ->with(['equipment.machine.line', 'parts.part'])
                ->get()
        );
    }

    public function store(StoreTaskLibraryRequest $request, Equipment $equipment): TaskLibraryResource
    {
        $library = $equipment->taskLibraries()->create($request->validated());

        return new TaskLibraryResource($library->load(['equipment.machine.line', 'parts.part']));
    }

    public function show(TaskLibrary $taskLibrary): TaskLibraryResource
    {
        return new TaskLibraryResource($taskLibrary->load(['equipment.machine.line', 'parts.part']));
    }

    public function update(UpdateTaskLibraryRequest $request, TaskLibrary $taskLibrary): TaskLibraryResource
    {
        $taskLibrary->update($request->validated());

        return new TaskLibraryResource($taskLibrary->load(['equipment.machine.line', 'parts.part']));
    }

    public function destroy(TaskLibrary $taskLibrary): Response
    {
        $taskLibrary->delete();

        return response()->noContent();
    }

    /**
     * Turn this library entry into an actual dated Task — the moment a "PM
     * task" becomes a "WO PM Schedule".
     */
    public function schedule(ScheduleTaskLibraryRequest $request, TaskLibrary $taskLibrary): TaskResource
    {
        $data = $request->validated();

        $task = $this->scheduling->schedule($taskLibrary, $data['due_date'], $data['assigned_to'] ?? null);

        return new TaskResource(
            $task->load(['equipment.machine.line.branch', 'assignee', 'taskLibrary', 'partChecks.part'])
        );
    }
}
