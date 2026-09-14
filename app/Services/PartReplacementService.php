<?php

namespace App\Services;

use App\Enums\ReplacementRequestStatus;
use App\Exceptions\PartStockNotFoundException;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approving a breakdown replacement request does three things atomically:
 * closes whatever installation of that part is currently active on the
 * equipment (if any), opens a new one, and issues the part out of that
 * branch's stock — the same ledger-backed path every other stock
 * consumption in the app goes through.
 */
class PartReplacementService
{
    public function __construct(private readonly StockMovementService $stockMovements) {}

    /**
     * @throws PartStockNotFoundException if the part has no stock record in the equipment's branch.
     */
    public function approve(PartReplacementRequest $request, User $reviewer, ?string $notes = null): PartReplacementRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $equipment = $request->equipment()->with('machine.line.branch')->first();
            $branchId = $equipment->machine->line->branch_id;

            $partStock = PartStock::where('part_id', $request->part_id)
                ->where('branch_id', $branchId)
                ->first();

            if (! $partStock) {
                throw new PartStockNotFoundException($request->part_id, $branchId);
            }

            // Close whatever's currently installed there for this part (a
            // straight swap), same rule as the manual "pasang part" flow.
            $equipment->partInstallations()
                ->where('part_id', $request->part_id)
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removed_at_runtime_hours' => $equipment->machine->line->runtime_hours,
                ]);

            $installation = $equipment->partInstallations()->create([
                'part_id' => $request->part_id,
                'installed_at' => now(),
                'installed_at_runtime_hours' => $equipment->machine->line->runtime_hours,
                'installed_by' => $reviewer->id,
                'notes' => "Breakdown: diajukan oleh {$request->requested_by_name}".
                    ($request->reason ? " — {$request->reason}" : ''),
            ]);

            $this->stockMovements->record(
                partStock: $partStock,
                type: StockLedger::TYPE_ISSUE,
                quantityChange: -$request->quantity_used,
                user: $reviewer,
                notes: "Penggantian breakdown oleh {$request->requested_by_name}",
                reference: $request,
            );

            $request->update([
                'status' => ReplacementRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'part_installation_id' => $installation->id,
            ]);

            return $request;
        });
    }

    public function reject(PartReplacementRequest $request, User $reviewer, ?string $notes = null): PartReplacementRequest
    {
        $request->update([
            'status' => ReplacementRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $request;
    }
}
