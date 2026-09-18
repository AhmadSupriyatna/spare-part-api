<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use App\Models\RackLevel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LocationController extends Controller
{
    /**
     * Flat list of every bin in a branch — still used by the plain
     * Select-based "assign part to location" pickers elsewhere (Part
     * Stock list, Location detail page), which don't need the rack/level
     * grouping the visual map on the Lokasi page uses (see RackController).
     */
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return LocationResource::collection(
            $branch->locations()->with('rackLevel.rack')->withCount('partStocks')->orderBy('code')->get()
        );
    }

    public function store(StoreLocationRequest $request, RackLevel $rackLevel): LocationResource
    {
        $binNumber = Location::nextBinNumber($rackLevel->id);

        $location = $rackLevel->locations()->create([
            'branch_id' => $rackLevel->rack->branch_id,
            'bin_number' => $binNumber,
            'code' => Location::generateCode($rackLevel, $binNumber),
            'description' => $request->validated()['description'] ?? null,
            'is_active' => true,
        ]);

        return new LocationResource($location->load('rackLevel.rack'));
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location->load('rackLevel.rack')->loadCount('partStocks'));
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location->load('rackLevel.rack')->loadCount('partStocks'));
    }

    public function destroy(Location $location): Response
    {
        $location->delete();

        return response()->noContent();
    }
}
