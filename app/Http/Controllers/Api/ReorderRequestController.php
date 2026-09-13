<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReorderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReorderRequest;
use App\Http\Requests\MarkReorderOrderedRequest;
use App\Http\Resources\ReorderRequestResource;
use App\Models\Branch;
use App\Models\ReorderRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReorderRequestController extends Controller
{
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = ReorderRequest::query()
            ->whereHas('partStock', fn ($q) => $q->where('branch_id', $branch->id))
            ->with(['partStock.part', 'supplier', 'requestedBy', 'approvedBy']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return ReorderRequestResource::collection($query->latest()->get());
    }

    public function approve(Request $request, ReorderRequest $reorderRequest): ReorderRequestResource
    {
        $reorderRequest->update([
            'status' => ReorderStatus::Approved,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return new ReorderRequestResource($reorderRequest);
    }

    public function markOrdered(MarkReorderOrderedRequest $request, ReorderRequest $reorderRequest): ReorderRequestResource
    {
        $reorderRequest->update([
            'status' => ReorderStatus::Ordered,
            'notes' => $request->validated()['notes'] ?? $reorderRequest->notes,
        ]);

        return new ReorderRequestResource($reorderRequest);
    }

    public function cancel(CancelReorderRequest $request, ReorderRequest $reorderRequest): ReorderRequestResource
    {
        $reorderRequest->update([
            'status' => ReorderStatus::Cancelled,
            'notes' => $request->validated()['notes'] ?? $reorderRequest->notes,
        ]);

        return new ReorderRequestResource($reorderRequest);
    }
}
