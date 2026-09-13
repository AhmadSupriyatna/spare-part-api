<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartRequest;
use App\Http\Requests\UpdatePartRequest;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Services\PartImageService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PartController extends Controller
{
    public function __construct(private readonly PartImageService $images) {}

    public function index(): AnonymousResourceCollection
    {
        return PartResource::collection(
            Part::with('stocks')->orderBy('name')->get()
        );
    }

    public function store(StorePartRequest $request): PartResource
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->images->store($request->file('image'));
        }

        $part = Part::create($data);

        return new PartResource($part);
    }

    public function show(Part $part): PartResource
    {
        return new PartResource($part->load('stocks.branch', 'stocks.location'));
    }

    public function update(UpdatePartRequest $request, Part $part): PartResource
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $this->images->delete($part->image_path);
            $data['image_path'] = $this->images->store($request->file('image'));
        }

        $part->update($data);

        return new PartResource($part);
    }

    public function destroy(Part $part): Response
    {
        $this->images->delete($part->image_path);
        $part->delete();

        return response()->noContent();
    }
}
