<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLineRequest;
use App\Http\Requests\UpdateLineRequest;
use App\Http\Resources\LineResource;
use App\Models\Branch;
use App\Models\ProductionLine;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LineController extends Controller
{
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return LineResource::collection($branch->lines()->orderBy('name')->get());
    }

    public function store(StoreLineRequest $request, Branch $branch): LineResource
    {
        $line = $branch->lines()->create($request->validated());

        return new LineResource($line);
    }

    public function show(ProductionLine $line): LineResource
    {
        return new LineResource($line);
    }

    public function update(UpdateLineRequest $request, ProductionLine $line): LineResource
    {
        $line->update($request->validated());

        return new LineResource($line);
    }

    public function destroy(ProductionLine $line): Response
    {
        $line->delete();

        return response()->noContent();
    }
}
