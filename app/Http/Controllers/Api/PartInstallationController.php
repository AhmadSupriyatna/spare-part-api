<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PartUnitAlreadyInstalledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleLifetimeReplacementRequest;
use App\Http\Requests\StorePartInstallationRequest;
use App\Http\Requests\UpdatePartInstallationRequest;
use App\Http\Resources\PartInstallationResource;
use App\Http\Resources\TaskResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Services\PartLifecycleService;
use App\Services\PartLifetimeService;
use App\Services\PmSchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartInstallationController extends Controller
{
    public function __construct(
        private readonly PartLifetimeService $lifetime,
        private readonly PmSchedulingService $scheduling,
        private readonly PartLifecycleService $lifecycle,
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
                ->with(['part', 'partUnit', 'installedBy'])
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
                ->with(['equipment.machine.line', 'partUnit', 'installedBy'])
                ->orderByDesc('installed_at')
                ->get()
        );
    }

    /**
     * Install a part onto an equipment — either a brand-new unit (no
     * part_unit_id given) or an existing repaired one being put back into
     * service. Multiple simultaneous units of the same part can be active
     * on one equipment at once; nothing here auto-closes another
     * installation just for sharing the same part_id.
     */
    public function store(StorePartInstallationRequest $request, Equipment $equipment): PartInstallationResource|JsonResponse
    {
        try {
            $installation = $this->lifecycle->install($equipment, $request->validated(), $request->user());
        } catch (PartUnitAlreadyInstalledException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new PartInstallationResource($installation->load(['part', 'partUnit', 'installedBy']));
    }

    public function remove(PartInstallation $partInstallation): PartInstallationResource
    {
        $installation = $this->lifecycle->remove($partInstallation);

        return new PartInstallationResource($installation->load(['part', 'partUnit', 'installedBy']));
    }

    public function update(
        UpdatePartInstallationRequest $request,
        PartInstallation $partInstallation
    ): PartInstallationResource {
        $partInstallation->update($request->validated());

        return new PartInstallationResource($partInstallation->load(['part', 'partUnit', 'installedBy']));
    }
}
