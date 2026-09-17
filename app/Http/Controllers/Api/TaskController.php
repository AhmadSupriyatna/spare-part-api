<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\PartStockNotFoundException;
use App\Exceptions\PartUnitAlreadyInstalledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTaskRequest;
use App\Http\Requests\CompleteTaskRequest;
use App\Http\Requests\RescheduleTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskRescheduleResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Task;
use App\Models\TaskReschedule;
use App\Services\PartLifetimeService;
use App\Services\PmSchedulingService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly PmSchedulingService $scheduling,
        private readonly PartLifetimeService $lifetime,
    ) {}

    /**
     * Every reschedule action ever recorded for a PM task in this branch,
     * newest first — the "Riwayat Reschedule" list below the PM calendar.
     */
    public function rescheduleHistory(Branch $branch): AnonymousResourceCollection
    {
        return TaskRescheduleResource::collection(
            TaskReschedule::query()
                ->whereHas(
                    'task',
                    fn ($q) => $q->where(fn ($q2) => $q2->whereNotNull('task_library_id')->orWhereHas('partChecks'))
                        ->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branch->id))
                )
                ->with(['task.equipment', 'rescheduledBy'])
                ->latest()
                ->get()
        );
    }

    /**
     * Every task in the branch, regardless of source — Task Library recipe
     * (task_library_id set), Part Lifetime-worn flag (no task_library_id,
     * but always leaves a task_part_checks row), or ad-hoc/breakdown
     * (neither). Feeds the single Maintenance page (Calendar + List WO
     * views). Also the trigger point for Weekly/Monthly Task Library
     * auto-recurrence and Part Lifetime auto-scheduling, since there's no
     * real cron running in local dev — every page load "catches up" any
     * missing occurrence for either source.
     */
    public function pmSchedule(Branch $branch): AnonymousResourceCollection
    {
        $this->scheduling->ensureRecurringSchedules($branch);
        $this->lifetime->autoScheduleAllAtRisk($branch->id);

        return TaskResource::collection(
            Task::query()
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
     * The Workspace task pool: tasks already claimed by the current user,
     * plus — for Engineer/Supervisor/Superadmin, who are the only roles
     * that can actually work a WO — every still-unclaimed task, so the
     * work is visible to whoever from the right level picks it up first
     * (see ensureCanWork(), which does the actual claiming on start/
     * complete/cancel). Scoped to whichever branch is active in the
     * header, same as every other list in the app; branch_id is clamped
     * to the user's own branches unless they're branch-exempt, mirroring
     * SearchController since this route isn't bound to a branch model for
     * EnsureBranchAccess to check automatically.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $branchId = $request->integer('branch_id') ?: null;

        if ($branchId && ! $user->hasAnyRole(['superadmin', 'supervisor'])) {
            $allowed = $user->branches()->pluck('branches.id');
            if (! $allowed->contains($branchId)) {
                $branchId = null;
            }
        }

        $canClaim = $user->hasAnyRole(['engineer', 'supervisor', 'superadmin']);

        return TaskResource::collection(
            Task::query()
                ->when(
                    $branchId,
                    fn ($q) => $q->whereHas('equipment.machine.line', fn ($q2) => $q2->where('branch_id', $branchId))
                )
                ->where(function ($q) use ($user, $canClaim) {
                    $q->where('assigned_to', $user->id);
                    if ($canClaim) {
                        $q->orWhereNull('assigned_to');
                    }
                })
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

    /**
     * Drag-to-reschedule on the PM calendar — unlike update(), this always
     * leaves a TaskReschedule log entry (previous date, new date, reason)
     * instead of silently overwriting due_date.
     */
    public function reschedule(RescheduleTaskRequest $request, Task $task): TaskResource
    {
        $data = $request->validated();

        $updated = DB::transaction(function () use ($task, $data, $request) {
            TaskReschedule::create([
                'task_id' => $task->id,
                'previous_due_date' => $task->due_date,
                'new_due_date' => $data['due_date'],
                'reason' => $data['reason'] ?? null,
                'rescheduled_by' => $request->user()->id,
            ]);

            $task->update(['due_date' => $data['due_date']]);

            return $task->fresh();
        });

        return new TaskResource(
            $updated->load(['equipment.machine.line.branch', 'assignee', 'taskLibrary', 'partChecks.part'])
        );
    }

    public function start(Request $request, Task $task): TaskResource
    {
        $this->ensureCanWork($request, $task);

        return new TaskResource($this->tasks->start($task));
    }

    public function complete(CompleteTaskRequest $request, Task $task): JsonResponse|TaskResource
    {
        $this->ensureCanWork($request, $task);

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
        } catch (PartStockNotFoundException|PartUnitAlreadyInstalledException|AmbiguousInstallationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new TaskResource($updated->load(['partStock.part', 'partChecks.part']));
    }

    public function cancel(CancelTaskRequest $request, Task $task): TaskResource
    {
        $this->ensureCanWork($request, $task);

        return new TaskResource(
            $this->tasks->cancel($task, $request->validated()['notes'] ?? null)
        );
    }

    /**
     * Tasks are no longer handed to one specific person up front — Task
     * Library/Part Lifetime auto-scheduling always leaves them unclaimed
     * (see PmSchedulingService). Only Engineer/Supervisor/Superadmin can
     * actually work a WO at all; the first one of them to start/complete/
     * cancel an unclaimed task claims it (assigned_to is set here), and
     * from then on only that person (or Superadmin) may act on it —
     * closes the gap where anyone could act on anyone else's claimed work.
     */
    private function ensureCanWork(Request $request, Task $task): void
    {
        $user = $request->user();

        if ($task->assigned_to !== null) {
            abort_unless(
                $task->assigned_to === $user->id || $user->hasRole('superadmin'),
                403,
                'Tugas ini sudah diklaim oleh pengguna lain.'
            );

            return;
        }

        abort_unless(
            $user->hasAnyRole(['engineer', 'supervisor', 'superadmin']),
            403,
            'Hanya Supervisor dan Engineer yang bisa mengerjakan WO.'
        );

        $task->update(['assigned_to' => $user->id]);
    }

    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
