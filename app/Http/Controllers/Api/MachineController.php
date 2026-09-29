<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyWithPasswordRequest;
use App\Http\Requests\StoreMachineRequest;
use App\Http\Requests\StoreOutsideLineMachineRequest;
use App\Http\Requests\UpdateMachineRequest;
use App\Http\Resources\MachineResource;
use App\Models\Branch;
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
        $data = $request->validated();
        $data['code'] = Machine::generateCode($data['name'], $line->id);

        $machine = $line->machines()->create($data);

        return new MachineResource($machine);
    }

    /**
     * "Mesin Luar Line" — every outside-line machine for this branch (a
     * compressor room, a genset...), independent of any production Line.
     * The existing line-scoped index() above is completely unchanged and
     * still the only way to list production Machines.
     */
    public function indexOutsideLine(Branch $branch): AnonymousResourceCollection
    {
        return MachineResource::collection($branch->machines()->orderBy('name')->get());
    }

    /**
     * Creates an outside-line machine directly under a Branch — no fake
     * Line. Code is system-generated the same way production Machine's is,
     * just scoped by Branch instead of Line (see Machine::generateCodeForBranch()).
     */
    public function storeOutsideLine(StoreOutsideLineMachineRequest $request, Branch $branch): MachineResource
    {
        $data = $request->validated();
        $data['code'] = Machine::generateCodeForBranch($data['name'], $branch->id);

        $machine = $branch->machines()->create($data);

        return new MachineResource($machine);
    }

    public function show(Machine $machine): MachineResource
    {
        return new MachineResource($machine->load(['line', 'branch']));
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
