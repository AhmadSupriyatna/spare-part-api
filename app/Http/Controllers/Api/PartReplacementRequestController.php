<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PartStockNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewReplacementRequestRequest;
use App\Http\Resources\PartReplacementRequestResource;
use App\Models\Branch;
use App\Models\PartReplacementRequest;
use App\Services\PartReplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartReplacementRequestController extends Controller
{
    public function __construct(private readonly PartReplacementService $replacements) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = PartReplacementRequest::query()
            ->whereHas(
                'equipment.machine.line',
                fn ($q) => $q->where('branch_id', $branch->id)
            )
            ->with(['part', 'equipment.machine.line.branch', 'reviewedBy']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return PartReplacementRequestResource::collection($query->latest()->get());
    }

    public function approve(ReviewReplacementRequestRequest $request, PartReplacementRequest $partReplacementRequest): JsonResponse|PartReplacementRequestResource
    {
        try {
            $this->replacements->approve(
                $partReplacementRequest,
                $request->user(),
                $request->validated()['notes'] ?? null,
            );
        } catch (PartStockNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new PartReplacementRequestResource(
            $partReplacementRequest->load(['part', 'equipment.machine.line.branch', 'reviewedBy'])
        );
    }

    public function reject(ReviewReplacementRequestRequest $request, PartReplacementRequest $partReplacementRequest): PartReplacementRequestResource
    {
        $this->replacements->reject(
            $partReplacementRequest,
            $request->user(),
            $request->validated()['notes'] ?? null,
        );

        return new PartReplacementRequestResource(
            $partReplacementRequest->load(['part', 'equipment.machine.line.branch', 'reviewedBy'])
        );
    }
}
