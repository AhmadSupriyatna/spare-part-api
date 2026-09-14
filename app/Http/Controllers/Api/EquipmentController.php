<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEquipmentRequest;
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

    /**
     * Flat list of every equipment in a branch, across all its lines/machines.
     */
    public function forBranch(Branch $branch): AnonymousResourceCollection
    {
        return EquipmentResource::collection(
            Equipment::whereHas('machine.line', fn ($query) => $query->where('branch_id', $branch->id))
                ->with('machine.line')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreEquipmentRequest $request, Machine $machine): EquipmentResource
    {
        $equipment = $machine->equipment()->create($request->validated());

        return new EquipmentResource($equipment);
    }

    public function show(Equipment $equipment): EquipmentResource
    {
        return new EquipmentResource($equipment->load('machine.line'));
    }

    public function update(UpdateEquipmentRequest $request, Equipment $equipment): EquipmentResource
    {
        $equipment->update($request->validated());

        return new EquipmentResource($equipment);
    }

    public function destroy(Equipment $equipment): Response
    {
        $equipment->delete();

        return response()->noContent();
    }
}
