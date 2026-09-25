<?php

namespace App\Services;

use App\Enums\ReplacementRequestStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\InvalidReplacementSelectionException;
use App\Exceptions\PartStockNotFoundException;
use App\Exceptions\ReplacementRequestAlreadyReviewedException;
use App\Models\Equipment;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartReplacementRequestItem;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approving a breakdown replacement request does the following atomically,
 * for every physical unit involved (one for a plain quantity_used = 1
 * request, N for a multi-unit one): closes the old installation (if any),
 * opens a new one through PartLifecycleService (so it gets a PartUnit like
 * every other install path, and inherits the old installation's slot_label),
 * records one old->new pairing row, and issues the part out of that
 * branch's stock exactly once for the whole request's quantity_used — never
 * once per unit, so the ledger reflects one real transaction, not N.
 */
class PartReplacementService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly PartLifecycleService $lifecycle,
    ) {}

    /**
     * @param  int[]  $oldInstallationIds  Which currently-active installations of this part on this
     *                                     equipment are being closed out. For a quantity_used = 1 request this may be
     *                                     left empty (auto-resolved, same as before — throws AmbiguousInstallationException
     *                                     if more than one is active). For quantity_used > 1 it's mandatory: exactly
     *                                     quantity_used distinct, active installations of this part on this equipment.
     *
     * @throws PartStockNotFoundException if the part has no stock record in the equipment's branch.
     * @throws ReplacementRequestAlreadyReviewedException if this request was already approved/rejected —
     *                                                    guards against a double-click or two reviewers processing the same request at once.
     * @throws AmbiguousInstallationException if quantity_used = 1, the equipment has more than one active
     *                                        installation of this part, and no explicit pick was given.
     * @throws InvalidReplacementSelectionException if the picked installations don't satisfy quantity_used
     *                                              (wrong count, a duplicate, or not actually an active installation of this part/equipment).
     */
    public function approve(
        PartReplacementRequest $request,
        User $reviewer,
        ?string $notes = null,
        array $oldInstallationIds = [],
    ): PartReplacementRequest {
        return DB::transaction(function () use ($request, $reviewer, $notes, $oldInstallationIds) {
            // Lock the request row itself so a concurrent approve/reject on the
            // same id can't slip through the status check below before this
            // transaction commits.
            $locked = PartReplacementRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReplacementRequestStatus::Pending) {
                throw new ReplacementRequestAlreadyReviewedException($locked->id, $locked->status);
            }

            $equipment = $locked->equipment()->with('machine.line.branch')->first();
            $branchId = $equipment->resolvedBranchId();

            $partStock = PartStock::where('part_id', $locked->part_id)
                ->where('branch_id', $branchId)
                ->first();

            if (! $partStock) {
                throw new PartStockNotFoundException($locked->part_id, $branchId);
            }

            $oldInstallations = $this->resolveOldInstallations($locked, $equipment, $oldInstallationIds);

            $eventLabel = $locked->event_type->label();
            $pairs = [];

            foreach ($oldInstallations as $oldInstallation) {
                if ($oldInstallation) {
                    $this->lifecycle->remove($oldInstallation);
                }

                // Always a brand-new unit off the shelf — breakdown has no way
                // to scan/identify a specific repaired unit going back in,
                // that's what the separate QR-per-unit reinstall flow is for.
                $newInstallation = $this->lifecycle->install($equipment, [
                    'part_id' => $locked->part_id,
                    'replacing_installation_id' => $oldInstallation?->id,
                    'notes' => "{$eventLabel}: diajukan oleh {$locked->requested_by_name}".
                        ($locked->reason ? " — {$locked->reason}" : ''),
                ], $reviewer);

                $pairs[] = ['old' => $oldInstallation, 'new' => $newInstallation];
            }

            // One stock movement for the whole request, regardless of how many
            // units it covers — never one per unit.
            $this->stockMovements->record(
                partStock: $partStock,
                type: StockLedger::TYPE_ISSUE,
                quantityChange: -$locked->quantity_used,
                user: $reviewer,
                notes: "{$eventLabel}: penggantian oleh {$locked->requested_by_name}",
                reference: $locked,
            );

            foreach ($pairs as $pair) {
                PartReplacementRequestItem::create([
                    'replacement_request_id' => $locked->id,
                    'old_installation_id' => $pair['old']?->id,
                    'new_installation_id' => $pair['new']->id,
                ]);
            }

            $locked->update([
                'status' => ReplacementRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'part_installation_id' => $pairs[0]['new']->id,
            ]);

            return $locked;
        });
    }

    /**
     * @param  int[]  $oldInstallationIds
     * @return Collection<int, PartInstallation|null>
     */
    private function resolveOldInstallations(PartReplacementRequest $request, Equipment $equipment, array $oldInstallationIds): Collection
    {
        if ($request->quantity_used === 1) {
            if (count($oldInstallationIds) > 1) {
                throw new InvalidReplacementSelectionException('Jumlah unit yang dipilih harus tepat 1.');
            }

            if (! empty($oldInstallationIds)) {
                // An explicit pick must actually be an active installation of
                // this part on this equipment — never trust the ID alone, the
                // same ownership check the quantity > 1 path already does
                // below, and the same lock so a concurrent approval touching
                // this exact installation can't race past it.
                $old = PartInstallation::whereKey($oldInstallationIds[0])
                    ->where('equipment_id', $equipment->id)
                    ->where('part_id', $request->part_id)
                    ->whereNull('removed_at')
                    ->lockForUpdate()
                    ->first();

                if (! $old) {
                    throw new InvalidReplacementSelectionException(
                        'Unit yang dipilih tidak valid — bukan milik equipment/part ini, atau sudah tidak aktif.'
                    );
                }

                return collect([$old]);
            }

            // No explicit pick: preserve the exact legacy behaviour —
            // auto-resolve (throws AmbiguousInstallationException if more
            // than one is active), and it's fine for there to be no old
            // installation to close out at all — a new one still gets
            // installed either way.
            $old = $this->lifecycle->resolveActiveInstallation($equipment, $request->part_id, null);

            return collect([$old]);
        }

        if (empty($oldInstallationIds)) {
            throw new InvalidReplacementSelectionException(
                "Pilih {$request->quantity_used} unit yang akan diganti."
            );
        }

        if (count($oldInstallationIds) !== count(array_unique($oldInstallationIds))) {
            throw new InvalidReplacementSelectionException('Tidak boleh memilih unit yang sama dua kali.');
        }

        if (count($oldInstallationIds) !== $request->quantity_used) {
            throw new InvalidReplacementSelectionException(
                "Jumlah unit yang dipilih harus tepat {$request->quantity_used}."
            );
        }

        $installations = PartInstallation::whereIn('id', $oldInstallationIds)
            ->where('equipment_id', $equipment->id)
            ->where('part_id', $request->part_id)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->get();

        if ($installations->count() !== count($oldInstallationIds)) {
            throw new InvalidReplacementSelectionException(
                'Salah satu unit yang dipilih tidak valid — bukan milik equipment/part ini, atau sudah tidak aktif.'
            );
        }

        return $installations->values();
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
