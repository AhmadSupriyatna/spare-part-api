<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReplacementRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReplacementRequestRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\EquipmentResource;
use App\Http\Resources\LineResource;
use App\Http\Resources\MachineResource;
use App\Http\Resources\PartResource;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Unauthenticated endpoints backing the QR-scan breakdown flow: a
 * technician on the shop floor scans a part's QR code, picks where it's
 * going (branch > line > machine > equipment), and submits a replacement
 * request — no login, but nothing here writes to stock or installations.
 * That only happens once an Engineer/Teknisi approves it via the
 * authenticated approval board (see PartReplacementRequestController).
 */
class PublicBreakdownController extends Controller
{
    public function showPart(Part $part): PartResource
    {
        return new PartResource($part);
    }

    public function branches(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::where('is_active', true)->orderBy('name')->get());
    }

    public function lines(Branch $branch): AnonymousResourceCollection
    {
        return LineResource::collection(
            $branch->lines()->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function machines(ProductionLine $line): AnonymousResourceCollection
    {
        return MachineResource::collection(
            $line->machines()->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function equipment(Machine $machine): AnonymousResourceCollection
    {
        return EquipmentResource::collection(
            $machine->equipment()->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function store(StoreReplacementRequestRequest $request): JsonResponse
    {
        $replacementRequest = PartReplacementRequest::create([
            ...$request->validated(),
            'status' => ReplacementRequestStatus::Pending,
        ]);

        return response()->json([
            'message' => 'Permintaan penggantian terkirim. Menunggu persetujuan Engineer/Teknisi.',
            'data' => ['id' => $replacementRequest->id],
        ], 201);
    }
}
