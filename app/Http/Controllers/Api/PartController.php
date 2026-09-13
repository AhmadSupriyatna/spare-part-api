<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartRequest;
use App\Http\Requests\UpdatePartRequest;
use App\Http\Resources\PartResource;
use App\Models\Part;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PartController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PartResource::collection(
            Part::with('stocks')->orderBy('name')->get()
        );
    }

    public function store(StorePartRequest $request): PartResource
    {
        $part = Part::create($request->validated());

        return new PartResource($part);
    }

    public function show(Part $part): PartResource
    {
        return new PartResource($part->load('stocks.branch', 'stocks.location'));
    }

    public function update(UpdatePartRequest $request, Part $part): PartResource
    {
        $part->update($request->validated());

        return new PartResource($part);
    }

    public function destroy(Part $part): Response
    {
        $part->delete();

        return response()->noContent();
    }
}
