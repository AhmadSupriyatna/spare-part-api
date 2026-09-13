<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    public function index(Branch $branch): AnonymousResourceCollection
    {
        return SupplierResource::collection($branch->suppliers()->orderBy('name')->get());
    }

    public function store(StoreSupplierRequest $request, Branch $branch): SupplierResource
    {
        $supplier = $branch->suppliers()->create($request->validated());

        return new SupplierResource($supplier);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    public function destroy(Supplier $supplier): Response
    {
        $supplier->delete();

        return response()->noContent();
    }
}
