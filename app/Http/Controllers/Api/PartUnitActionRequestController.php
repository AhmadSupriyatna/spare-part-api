<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PartUnitAlreadyInstalledException;
use App\Exceptions\PartUnitNotAvailableException;
use App\Exceptions\PartUnitNotInstalledException;
use App\Exceptions\ReplacementRequestAlreadyReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewPartUnitActionRequest;
use App\Http\Resources\PartUnitActionRequestResource;
use App\Models\Branch;
use App\Models\PartUnitActionRequest;
use App\Services\PartUnitActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PartUnitActionRequestController extends Controller
{
    public function __construct(private readonly PartUnitActionService $actions) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $query = PartUnitActionRequest::query()
            ->where('branch_id', $branch->id)
            ->with(['partUnit.part', 'equipment.machine.line', 'reviewedBy']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return PartUnitActionRequestResource::collection($query->latest()->get());
    }

    public function approve(
        ReviewPartUnitActionRequest $request,
        PartUnitActionRequest $partUnitActionRequest
    ): JsonResponse|PartUnitActionRequestResource {
        try {
            $this->actions->approve(
                $partUnitActionRequest,
                $request->user(),
                $request->validated()['notes'] ?? null,
            );
        } catch (PartUnitNotInstalledException|PartUnitNotAvailableException|PartUnitAlreadyInstalledException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ReplacementRequestAlreadyReviewedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new PartUnitActionRequestResource(
            $partUnitActionRequest->refresh()->load(['partUnit.part', 'equipment.machine.line', 'reviewedBy'])
        );
    }

    public function reject(
        ReviewPartUnitActionRequest $request,
        PartUnitActionRequest $partUnitActionRequest
    ): JsonResponse|PartUnitActionRequestResource {
        try {
            $this->actions->reject(
                $partUnitActionRequest,
                $request->user(),
                $request->validated()['notes'] ?? null,
            );
        } catch (ReplacementRequestAlreadyReviewedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new PartUnitActionRequestResource(
            $partUnitActionRequest->refresh()->load(['partUnit.part', 'equipment.machine.line', 'reviewedBy'])
        );
    }
}
