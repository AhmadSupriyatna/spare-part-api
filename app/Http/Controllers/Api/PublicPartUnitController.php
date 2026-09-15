<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartUnitActionType;
use App\Exceptions\PartUnitNotAvailableException;
use App\Exceptions\PartUnitNotInstalledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartUnitActionRequest;
use App\Http\Resources\PublicPartUnitResource;
use App\Models\PartUnit;
use App\Services\PartUnitActionService;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated endpoints backing the QR-per-unit scan flow: a technician
 * scans the QR stuck on a specific physical part unit and reports it being
 * pulled off or put back — no login, and (like PublicBreakdownController)
 * nothing here writes to PartUnit/PartInstallation directly. That only
 * happens once an Engineer/Teknisi approves it via the authenticated
 * approval board.
 */
class PublicPartUnitController extends Controller
{
    public function __construct(private readonly PartUnitActionService $actions) {}

    public function show(PartUnit $partUnit): PublicPartUnitResource
    {
        return new PublicPartUnitResource($partUnit->load('part'));
    }

    public function store(StorePartUnitActionRequest $request, PartUnit $partUnit): JsonResponse
    {
        $data = $request->validated();

        try {
            $actionRequest = $this->actions->request(
                unit: $partUnit,
                action: PartUnitActionType::from($data['action']),
                equipmentId: $data['equipment_id'] ?? null,
                requestedByName: $data['requested_by_name'],
                notes: $data['notes'] ?? null,
            );
        } catch (PartUnitNotInstalledException|PartUnitNotAvailableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Permintaan terkirim. Menunggu persetujuan Engineer/Teknisi.',
            'data' => ['id' => $actionRequest->id],
        ], 201);
    }
}
