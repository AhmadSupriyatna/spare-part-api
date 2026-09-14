<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartSupplierRequest;
use App\Http\Requests\UpdatePartSupplierRequest;
use App\Http\Resources\PartSupplierResource;
use App\Models\Part;
use App\Models\PartSupplier;
use App\Models\Supplier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PartSupplierController extends Controller
{
    public function index(Part $part): AnonymousResourceCollection
    {
        return PartSupplierResource::collection(
            $part->partSuppliers()->with(['supplier.branch'])->orderByDesc('is_preferred')->get()
        );
    }

    public function store(StorePartSupplierRequest $request, Part $part): PartSupplierResource
    {
        $data = $request->validated();

        if (! empty($data['is_preferred'])) {
            $part->partSuppliers()->update(['is_preferred' => false]);
        }

        $partSupplier = $part->partSuppliers()->create($data);

        return new PartSupplierResource($partSupplier->load('supplier.branch'));
    }

    public function update(UpdatePartSupplierRequest $request, PartSupplier $partSupplier): PartSupplierResource
    {
        $data = $request->validated();

        if (! empty($data['is_preferred'])) {
            PartSupplier::where('part_id', $partSupplier->part_id)
                ->where('id', '!=', $partSupplier->id)
                ->update(['is_preferred' => false]);
        }

        $partSupplier->update($data);

        return new PartSupplierResource($partSupplier->load('supplier.branch'));
    }

    public function destroy(PartSupplier $partSupplier): Response
    {
        $partSupplier->delete();

        return response()->noContent();
    }

    /**
     * Reverse lookup: which parts is this supplier approved to supply.
     */
    public function forSupplier(Supplier $supplier): AnonymousResourceCollection
    {
        return PartSupplierResource::collection(
            $supplier->partSuppliers()->with('part')->orderBy('is_preferred', 'desc')->get()
        );
    }
}
