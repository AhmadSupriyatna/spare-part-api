<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleLifetimeReplacementRequest;
use App\Http\Requests\StorePartInstallationRequest;
use App\Http\Resources\PartInstallationResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Services\PartLifetimeService;
use App\Services\PmSchedulingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PartInstallationController extends Controller
{
    public function __construct(
        private readonly PartLifetimeService $lifetime,
        private readonly PmSchedulingService $scheduling,
    ) {}

    /**
     * Installed parts in this branch whose remaining usable life has
     * dropped below 10% — candidates for the PM calendar's second
     * scheduling source alongside the Task Library.
     */
    public function atRisk(Branch $branch): AnonymousResourceCollection
    {
        return PartInstallationResource::collection($this->lifetime->atRiskInstallations($branch->id));
    }

    /**
     * Schedule this specific worn installation's replacement onto the PM
     * calendar — turns it into a Task with a one-part checklist.
     */
    public function scheduleReplacement(
        ScheduleLifetimeReplacementRequest $request,
        PartInstallation $partInstallation
    ): TaskResource {
        $data = $request->validated();

        $task = $this->scheduling->scheduleFromLifetime(
            $partInstallation,
            $data['due_date'],
            $data['assigned_to'] ?? null,
        );

        return new TaskResource(
            $task->load(['equipment.machine.line.branch', 'assignee', 'partChecks.part'])
        );
    }

    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return PartInstallationResource::collection(
            $equipment->partInstallations()
                ->with(['part', 'installedBy'])
                ->orderByDesc('installed_at')
                ->get()
        );
    }

    /**
     * Reverse lookup: everywhere this part is (or has been) installed.
     */
    public function forPart(Part $part): AnonymousResourceCollection
    {
        return PartInstallationResource::collection(
            $part->installations()
                ->with(['equipment.machine.line', 'installedBy'])
                ->orderByDesc('installed_at')
                ->get()
        );
    }

    /**
     * Install a part onto an equipment. If that part is already actively
     * installed there, the old installation is auto-closed (treated as
     * replaced) rather than allowing two active rows for the same pair.
     */
    public function store(StorePartInstallationRequest $request, Equipment $equipment): PartInstallationResource
    {
        $data = $request->validated();
        $currentRuntimeHours = $equipment->machine->line->runtime_hours;

        $installation = DB::transaction(function () use ($equipment, $request, $data, $currentRuntimeHours) {
            $equipment->partInstallations()
                ->where('part_id', $data['part_id'])
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removed_at_runtime_hours' => $currentRuntimeHours,
                ]);

            return $equipment->partInstallations()->create([
                'part_id' => $data['part_id'],
                'installed_at' => $data['installed_at'] ?? now(),
                'installed_at_runtime_hours' => $currentRuntimeHours,
                'installed_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return new PartInstallationResource($installation->load(['part', 'installedBy']));
    }

    public function remove(Request $request, PartInstallation $partInstallation): PartInstallationResource
    {
        $partInstallation->update([
            'removed_at' => now(),
            'removed_at_runtime_hours' => $partInstallation->equipment->machine->line->runtime_hours,
        ]);

        return new PartInstallationResource($partInstallation->load(['part', 'installedBy']));
    }
}
