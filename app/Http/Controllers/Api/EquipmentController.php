<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\StoreOutsideLineEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EquipmentController extends Controller
{
    public function index(Machine $machine): AnonymousResourceCollection
    {
        return EquipmentResource::collection($machine->equipment()->orderBy('name')->get());
    }

    public function store(StoreEquipmentRequest $request, Machine $machine): EquipmentResource
    {
        $data = $request->validated();
        $data['code'] = Equipment::generateCode($data['name'], $machine->id);

        $equipment = $machine->equipment()->create($data);

        return new EquipmentResource($equipment);
    }

    /**
     * Phase 3B "Asset Outside Line" — every outside-line asset for this
     * branch (an AC panel room, a hand pallet...), independent of any
     * Line/Machine. The existing machine-scoped index() above is
     * completely unchanged and still the only way to list production
     * Equipment.
     */
    public function indexOutsideLine(Branch $branch): AnonymousResourceCollection
    {
        return EquipmentResource::collection($branch->equipment()->orderBy('name')->get());
    }

    /**
     * Creates an outside-line asset directly under a Branch — no fake Line,
     * no fake Machine. Code is system-generated the same way production
     * Equipment's is, just scoped by Branch instead of Machine (see
     * Equipment::generateCodeForBranch()).
     */
    public function storeOutsideLine(StoreOutsideLineEquipmentRequest $request, Branch $branch): EquipmentResource
    {
        $data = $request->validated();
        $data['code'] = Equipment::generateCodeForBranch($data['name'], $branch->id);

        $equipment = $branch->equipment()->create($data);

        return new EquipmentResource($equipment);
    }

    public function show(Equipment $equipment): EquipmentResource
    {
        return new EquipmentResource($equipment->load(['machine.line', 'branch']));
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): EquipmentResource
    {
        $equipment->update($request->validated());

        return new EquipmentResource($equipment);
    }

    public function destroy(DestroyWithPasswordRequest $request, Equipment $equipment): Response
    {
        $equipment->delete();

        return response()->noContent();
    }
}
