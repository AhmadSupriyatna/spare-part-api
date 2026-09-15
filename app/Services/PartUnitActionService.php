<?php

namespace App\Services;

use App\Enums\PartUnitActionType;
use App\Enums\PartUnitStatus;
use App\Enums\ReplacementRequestStatus;
use App\Exceptions\PartUnitNotAvailableException;
use App\Exceptions\PartUnitNotInstalledException;
use App\Exceptions\ReplacementRequestAlreadyReviewedException;
use App\Models\Equipment;
use App\Models\PartUnit;
use App\Models\PartUnitActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Backs the QR-per-unit public flow: scanning a specific PartUnit's QR lets
 * someone on the shop floor, without logging in, report "I just pulled this
 * off" (remove) or "this repaired unit just went back in" (reinstall). Like
 * the breakdown flow, that submission only ever creates a pending request —
 * the actual PartUnit/PartInstallation state only changes once an
 * Engineer/Teknisi approves it here, through the same PartLifecycleService
 * path the authenticated in-app buttons use.
 */
class PartUnitActionService
{
    public function __construct(private readonly PartLifecycleService $lifecycle) {}

    /**
     * @throws PartUnitNotInstalledException if a "remove" is requested for a unit that isn't currently installed.
     * @throws PartUnitNotAvailableException if a "reinstall" is requested for a unit that isn't repaired/available.
     */
    public function request(
        PartUnit $unit,
        PartUnitActionType $action,
        ?int $equipmentId,
        string $requestedByName,
        ?string $notes,
    ): PartUnitActionRequest {
        if ($action === PartUnitActionType::Remove) {
            $installation = $unit->currentInstallation();

            if (! $installation) {
                throw new PartUnitNotInstalledException($unit->id);
            }

            $equipmentId = $installation->equipment_id;
            $branchId = $installation->equipment->machine->line->branch_id;
        } else {
            if ($unit->status !== PartUnitStatus::Available) {
                throw new PartUnitNotAvailableException($unit->id);
            }

            $equipment = Equipment::with('machine.line')->findOrFail($equipmentId);
            $branchId = $equipment->machine->line->branch_id;
        }

        return PartUnitActionRequest::create([
            'part_unit_id' => $unit->id,
            'action' => $action,
            'equipment_id' => $equipmentId,
            'branch_id' => $branchId,
            'requested_by_name' => $requestedByName,
            'notes' => $notes,
            'status' => ReplacementRequestStatus::Pending,
        ]);
    }

    /**
     * @throws PartUnitNotInstalledException if the unit was already removed by someone else since the request was made.
     * @throws PartUnitNotAvailableException if the unit is no longer available (e.g. already reinstalled elsewhere).
     * @throws ReplacementRequestAlreadyReviewedException if this request was already approved/rejected.
     */
    public function approve(PartUnitActionRequest $request, User $reviewer, ?string $notes = null): PartUnitActionRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $locked = PartUnitActionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReplacementRequestStatus::Pending) {
                throw new ReplacementRequestAlreadyReviewedException($locked->id, $locked->status);
            }

            $unit = $locked->partUnit;

            if ($locked->action === PartUnitActionType::Remove) {
                $installation = $unit->currentInstallation();

                if (! $installation) {
                    throw new PartUnitNotInstalledException($unit->id);
                }

                $installation = $this->lifecycle->remove($installation);
                $resultingInstallationId = $installation->id;
            } else {
                if ($unit->status !== PartUnitStatus::Available) {
                    throw new PartUnitNotAvailableException($unit->id);
                }

                $equipment = Equipment::findOrFail($locked->equipment_id);
                $installation = $this->lifecycle->install(
                    $equipment,
                    ['part_id' => $unit->part_id, 'part_unit_id' => $unit->id],
                    $reviewer,
                );
                $resultingInstallationId = $installation->id;
            }

            $locked->update([
                'status' => ReplacementRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'resulting_installation_id' => $resultingInstallationId,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ReplacementRequestAlreadyReviewedException if this request was already approved/rejected.
     */
    public function reject(PartUnitActionRequest $request, User $reviewer, ?string $notes = null): PartUnitActionRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $locked = PartUnitActionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReplacementRequestStatus::Pending) {
                throw new ReplacementRequestAlreadyReviewedException($locked->id, $locked->status);
            }

            $locked->update([
                'status' => ReplacementRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            return $locked;
        });
    }
}
