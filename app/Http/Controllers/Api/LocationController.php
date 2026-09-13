<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LocationController extends Controller
{
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return LocationResource::collection($branch->locations()->orderBy('code')->get());
    }

    public function store(StoreLocationRequest $request, Branch $branch): LocationResource
    {
        $location = $branch->locations()->create($request->validated());

        return new LocationResource($location);
    }

    public function show(Location $location): LocationResource
    {
        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->update($request->validated());

        return new LocationResource($location);
    }

    public function destroy(Location $location): Response
    {
        $location->delete();

        return response()->noContent();
    }
}
