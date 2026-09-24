<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FindingAlreadyDecidedException;
use App\Exceptions\FindingMissingPartException;
use App\Exceptions\InvalidFollowUpQuantityException;
use App\Exceptions\TaskLibraryEquipmentMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMaintenanceDecisionRequest;
use App\Http\Resources\MaintenanceDecisionResource;
use App\Models\Finding;
use App\Services\MaintenanceDecisionService;
use Illuminate\Http\JsonResponse;

class MaintenanceDecisionController extends Controller
{
    public function __construct(private readonly MaintenanceDecisionService $decisions) {}

    public function store(StoreMaintenanceDecisionRequest $request, Finding $finding): JsonResponse|MaintenanceDecisionResource
    {
        try {
            $decision = $this->decisions->decide($finding, $request->user(), $request->validated());
        } catch (FindingMissingPartException|InvalidFollowUpQuantityException|TaskLibraryEquipmentMismatchException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FindingAlreadyDecidedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new MaintenanceDecisionResource($decision);
    }
}
