<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEquipmentPartRequest;
use App\Http\Resources\EquipmentPartResource;
use App\Models\Equipment;
use App\Models\EquipmentPart;
use App\Models\Part;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EquipmentPartController extends Controller
{
    public function index(Equipment $equipment): AnonymousResourceCollection
    {
        return EquipmentPartResource::collection(
            $equipment->equipmentParts()->with('part')->get()
        );
    }

    public function store(StoreEquipmentPartRequest $request, Equipment $equipment): EquipmentPartResource
    {
        $equipmentPart = $equipment->equipmentParts()->create($request->validated());

        return new EquipmentPartResource($equipmentPart->load('part'));
    }

    public function destroy(EquipmentPart $equipmentPart): Response
    {
        $equipmentPart->delete();

        return response()->noContent();
    }

    /**
     * Reverse lookup: which equipment lists this part in its BOM.
     */
    public function forPart(Part $part): AnonymousResourceCollection
    {
        return EquipmentPartResource::collection(
            $part->equipmentParts()->with(['equipment.machine.line'])->get()
        );
    }
}
