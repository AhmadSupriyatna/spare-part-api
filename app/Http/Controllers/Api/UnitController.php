<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Unit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class UnitController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UnitResource::collection(Unit::orderBy('name')->get());
    }

    public function store(StoreUnitRequest $request): UnitResource
    {
        return new UnitResource(Unit::create($request->validated()));
    }

    public function update(UpdateUnitRequest $request, Unit $unit): UnitResource
    {
        $unit->update($request->validated());

        return new UnitResource($unit);
    }

    public function destroy(Unit $unit): Response
    {
        $unit->delete();

        return response()->noContent();
    }
}
