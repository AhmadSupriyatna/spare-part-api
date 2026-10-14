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
use Illuminate\Support\Facades\DB;

class TaskLibraryController extends Controller
{
    public function __construct(private readonly PmSchedulingService $scheduling) {}

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return TaskLibraryResource::collection(
            $equipment->taskLibraries()->with(['equipment.machine.line', 'parts.part', 'checklistItems.parts.part'])->orderBy('id')->get()
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
                ->with(['equipment.machine.line', 'parts.part', 'checklistItems.parts.part'])
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Creates the recipe and its checklist parts together in one transaction
     * — a library with a title but a half-saved checklist would be confusing
     * to land on, so this is all-or-nothing rather than the incremental
     * add/remove the "Ubah" sheet uses for an already-existing library.
     */
    public function store(StoreTaskLibraryRequest $request, Equipment $equipment): TaskLibraryResource
    {
        $data = $request->validated();
        $partsData = $data['parts'] ?? [];
        $checklistItemsData = $data['checklist_items'] ?? [];
        unset($data['parts'], $data['checklist_items']);

        $library = DB::transaction(function () use ($equipment, $data, $partsData, $checklistItemsData) {
            // Sequence is already scoped to this exact equipment_id (not by
            // code prefix), so it was never the source of collisions.
            $sequence = $equipment->taskLibraries()->count() + 1;
            // equipment.code alone is only unique WITHIN its own machine
            // (see Equipment::hierarchicalCode()) - two equipment under
            // different machines/lines can legitimately share one, which
            // used to collide here against task_libraries' globally-unique
            // code column. The hierarchical code is unique enough instead.
            $data['code'] = $equipment->hierarchicalCode().'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

            $library = $equipment->taskLibraries()->create($data);

            foreach ($partsData as $partData) {
                $library->parts()->create([
                    'part_id' => $partData['part_id'],
                    'quantity_required' => $equipment->activeInstallationCountForPart($partData['part_id']),
                ]);
            }

            foreach ($checklistItemsData as $order => $itemData) {
                $checklistItem = $library->checklistItems()->create([
                    'description' => $itemData['description'],
                    'order' => $order,
                ]);
                foreach ($itemData['parts'] ?? [] as $partData) {
                    $checklistItem->parts()->create([
                        'task_library_id' => $library->id,
                        'part_id' => $partData['part_id'],
                        'quantity_required' => $equipment->activeInstallationCountForPart($partData['part_id']),
                    ]);
                }
            }

            return $library;
        });

        return new TaskLibraryResource($library->load(['equipment.machine.line', 'parts.part', 'checklistItems.parts.part']));
    }

    public function show(TaskLibrary $taskLibrary): TaskLibraryResource
    {
        return new TaskLibraryResource($taskLibrary->load(['equipment.machine.line', 'parts.part', 'checklistItems.parts.part']));
    }

    public function update(UpdateTaskLibraryRequest $request, TaskLibrary $taskLibrary): TaskLibraryResource
    {
        $taskLibrary->update($request->validated());

        return new TaskLibraryResource($taskLibrary->load(['equipment.machine.line', 'parts.part', 'checklistItems.parts.part']));
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
            $task->load(['equipment.machine.line.branch', 'assignee', 'taskLibrary', 'partChecks.part', 'checklistItems'])
        );
    }
}
