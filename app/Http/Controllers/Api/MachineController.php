<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreMachineRequest;
use App\Http\Requests\UpdateMachineRequest;
use App\Http\Resources\MachineResource;
use App\Models\Machine;
use App\Models\ProductionLine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MachineController extends Controller
{
    public function index(ProductionLine $line): AnonymousResourceCollection
    {
        return MachineResource::collection($line->machines()->orderBy('name')->get());
    }

    public function store(StoreMachineRequest $request, ProductionLine $line): MachineResource
    {
        $machine = $line->machines()->create($request->validated());

        return new MachineResource($machine);
    }

    public function show(Machine $machine): MachineResource
    {
        return new MachineResource($machine->load('line'));
    }

    public function update(UpdateMachineRequest $request, Machine $machine): MachineResource
    {
        $machine->update($request->validated());

        return new MachineResource($machine);
    }

    public function destroy(DestroyWithPasswordRequest $request, Machine $machine): Response
    {
        $machine->delete();

        return response()->noContent();
    }
}
