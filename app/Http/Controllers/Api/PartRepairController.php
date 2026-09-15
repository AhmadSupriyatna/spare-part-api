<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartRepairDisposition;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartRepairRequest;
use App\Http\Requests\UpdatePartRepairRequest;
use App\Http\Resources\PartRepairResource;
use App\Models\PartRepair;
use App\Models\PartUnit;
use App\Services\PartLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartRepairController extends Controller
{
    public function __construct(private readonly PartLifecycleService $lifecycle) {}

    /**
     * Every repair record, company-wide (a PartUnit isn't tied to one
     * branch — it can move between equipment on different branches over its
     * life), optionally filtered to one disposition for a board-style view.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PartRepair::query()->with(['partUnit.part', 'partInstallation.equipment.machine.line']);

        if ($disposition = $request->string('disposition')->toString()) {
            $query->where('disposition', $disposition);
        }

        return PartRepairResource::collection($query->latest('removed_at')->get());
    }

    /**
     * Open a repair record for a unit that's just been (or was previously)
     * removed — the first step of deciding whether it gets fixed or scrapped.
     */
    public function store(StorePartRepairRequest $request, PartUnit $partUnit): PartRepairResource
    {
        $data = $request->validated();

        $repair = $this->lifecycle->sendToRepair(
            $partUnit,
            $data['part_installation_id'] ?? null,
            $data['notes'] ?? null,
        );

        return new PartRepairResource($repair->load('partUnit.part'));
    }

    /**
     * Move a repair forward: in_repair, repaired (unit becomes available to
     * reinstall), or scrapped (unit is done for good).
     */
    public function update(UpdatePartRepairRequest $request, PartRepair $partRepair): PartRepairResource
    {
        $data = $request->validated();

        $repair = $this->lifecycle->updateDisposition(
            $partRepair,
            PartRepairDisposition::from($data['disposition']),
            $data['notes'] ?? null,
        );

        return new PartRepairResource($repair->load('partUnit.part'));
    }
}
