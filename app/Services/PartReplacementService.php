<?php

namespace App\Services;

use App\Enums\ReplacementRequestStatus;
use App\Exceptions\PartStockNotFoundException;
use App\Exceptions\ReplacementRequestAlreadyReviewedException;
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
     * @throws ReplacementRequestAlreadyReviewedException if this request was already approved/rejected —
     *         guards against a double-click or two reviewers processing the same request at once.
     */
    public function approve(PartReplacementRequest $request, User $reviewer, ?string $notes = null): PartReplacementRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            // Lock the request row itself so a concurrent approve/reject on the
            // same id can't slip through the status check below before this
            // transaction commits.
            $locked = PartReplacementRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReplacementRequestStatus::Pending) {
                throw new ReplacementRequestAlreadyReviewedException($locked->id, $locked->status);
            }

            $equipment = $locked->equipment()->with('machine.line.branch')->first();
            $branchId = $equipment->machine->line->branch_id;

            $partStock = PartStock::where('part_id', $locked->part_id)
                ->where('branch_id', $branchId)
                ->first();

            if (! $partStock) {
                throw new PartStockNotFoundException($locked->part_id, $branchId);
            }

            // Close whatever's currently installed there for this part (a
            // straight swap), same rule as the manual "pasang part" flow.
            $equipment->partInstallations()
                ->where('part_id', $locked->part_id)
                ->whereNull('removed_at')
                ->update([
                    'removed_at' => now(),
                    'removed_at_runtime_hours' => $equipment->machine->line->runtime_hours,
                ]);

            $installation = $equipment->partInstallations()->create([
                'part_id' => $locked->part_id,
                'installed_at' => now(),
                'installed_at_runtime_hours' => $equipment->machine->line->runtime_hours,
                'installed_by' => $reviewer->id,
                'notes' => "Breakdown: diajukan oleh {$locked->requested_by_name}".
                    ($locked->reason ? " — {$locked->reason}" : ''),
            ]);

            $this->stockMovements->record(
                partStock: $partStock,
                type: StockLedger::TYPE_ISSUE,
                quantityChange: -$locked->quantity_used,
                user: $reviewer,
                notes: "Penggantian breakdown oleh {$locked->requested_by_name}",
                reference: $locked,
            );

            $locked->update([
                'status' => ReplacementRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'part_installation_id' => $installation->id,
            ]);

            return $locked;
        });
    }

    /**
     * @throws ReplacementRequestAlreadyReviewedException if this request was already approved/rejected.
     */
    public function reject(PartReplacementRequest $request, User $reviewer, ?string $notes = null): PartReplacementRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $locked = PartReplacementRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

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
