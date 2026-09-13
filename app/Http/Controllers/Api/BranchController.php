<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::orderBy('name')->get());
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $branch = Branch::create($request->validated());

        return new BranchResource($branch);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch);
    }

    public function destroy(Branch $branch): \Illuminate\Http\Response
    {
        $branch->delete();

        return response()->noContent();
    }
}
