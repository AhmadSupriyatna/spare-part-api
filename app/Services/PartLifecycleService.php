<?php

namespace App\Services;

use App\Enums\PartRepairDisposition;
use App\Enums\PartUnitStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\InvalidOldInstallationException;
use App\Exceptions\PartUnitAlreadyInstalledException;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a PartUnit through its whole life: install, remove, send for
 * repair, and either return to service (reinstall) or get scrapped. Every
 * step here keeps PartUnit.status and the linked PartInstallation/PartRepair
 * rows consistent so lifetime hours and install history stay traceable
 * across repair cycles (see PartUnit::percentUsed()/totalRuntimeHoursUsed()).
 */
class PartLifecycleService
{
    /**
     * @param  array{part_id: int, part_unit_id?: int|null, installed_at?: string|null, notes?: string|null, replacing_installation_id?: int|null}  $data
     *
     * @throws PartUnitAlreadyInstalledException if the chosen unit is already mounted somewhere.
     */
    public function install(Equipment $equipment, array $data, User $user): PartInstallation
    {
        return DB::transaction(function () use ($equipment, $data, $user) {
            // Phase 3B "Asset Outside Line": no Line means no runtime-hours
            // source (out of scope to solve here) — 0 rather than a crash;
            // Life Based/runtime lifetime tracking simply won't be
            // meaningful for such an asset's parts yet.
            $currentRuntimeHours = $equipment->machine?->line?->runtime_hours ?? 0;
            $pendingRepair = null;

            if (! empty($data['part_unit_id'])) {
                $unit = PartUnit::whereKey($data['part_unit_id'])->lockForUpdate()->firstOrFail();

                if ($unit->currentInstallation()) {
                    throw new PartUnitAlreadyInstalledException($unit->id);
                }

                $pendingRepair = $unit->repairs()->whereNull('reinstalled_installation_id')->latest('removed_at')->first();
            } else {
                $unit = PartUnit::create([
                    'part_id' => $data['part_id'],
                    'unit_code' => PartUnit::nextCode($data['part_id']),
                    'status' => PartUnitStatus::InService,
                ]);
            }

            $unit->update(['status' => PartUnitStatus::InService]);

            $slotLabel = $this->resolveSlotLabel($equipment, $data['part_id'], $data['replacing_installation_id'] ?? null);

            $installation = $equipment->partInstallations()->create([
                'part_id' => $data['part_id'],
                'part_unit_id' => $unit->id,
                'slot_label' => $slotLabel,
                'installed_at' => $data['installed_at'] ?? now(),
                'installed_at_runtime_hours' => $currentRuntimeHours,
                'installed_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $pendingRepair?->update(['reinstalled_installation_id' => $installation->id]);

            return $installation;
        });
    }

    /**
     * "Part Passport" — for a `has_passport` Part only, Stock In creates one
     * PartUnit per quantity received immediately, each with its own QR
     * (unit_code) and an `arrived_at` timestamp, status `Available` so it
     * shows up in PartPickerSheet's "available units" dropdown right away —
     * install() needs no changes, its existing `part_unit_id` branch already
     * handles "reuse an existing unit" exactly like a repaired-and-returned
     * one. Called from PartStockController::recordReceiving().
     *
     * @return Collection<int, PartUnit>
     */
    public function receiveUnits(Part $part, int $quantity): Collection
    {
        return DB::transaction(function () use ($part, $quantity) {
            $units = new Collection;

            for ($i = 0; $i < $quantity; $i++) {
                $units->push(PartUnit::create([
                    'part_id' => $part->id,
                    'unit_code' => PartUnit::nextCode($part->id),
                    'status' => PartUnitStatus::Available,
                    'arrived_at' => now(),
                ]));
            }

            return $units;
        });
    }

    /**
     * A part's "address" on one equipment (A, B, C...) — unlike
     * PartUnit.unit_code (a global running count across the whole part
     * catalog), this is scoped to just this equipment+part pair and stays
     * stable across a replacement: if this install is closing out a
     * specific old one ($replacingInstallationId), it inherits that
     * installation's slot_label directly. Otherwise (a fresh position, or
     * an additional unit alongside existing ones) it takes the lowest
     * letter not currently held by an active installation of that part
     * here — so a freed-up letter gets reused rather than always growing.
     */
    private function resolveSlotLabel(Equipment $equipment, int $partId, ?int $replacingInstallationId): string
    {
        if ($replacingInstallationId) {
            $inherited = PartInstallation::whereKey($replacingInstallationId)->value('slot_label');

            if ($inherited) {
                return $inherited;
            }
        }

        // Locked so two concurrent installs for the same equipment+part
        // (e.g. two technicians completing WOs on the same gearbox at once)
        // can't both read the same "used letters" snapshot and pick the
        // same free one — this whole method runs inside install()'s
        // transaction, so the lock holds until that commits.
        $usedLabels = $equipment->partInstallations()
            ->where('part_id', $partId)
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->pluck('slot_label')
            ->filter()
            ->all();

        for ($i = 0; $i < 26; $i++) {
            $candidate = chr(65 + $i);

            if (! in_array($candidate, $usedLabels, true)) {
                return $candidate;
            }
        }

        // 26 simultaneous active installations of one part on one equipment
        // is already absurd — fall back to a running count rather than throw.
        return (string) ($equipment->partInstallations()->where('part_id', $partId)->count() + 1);
    }

    /**
     * Figure out which currently-active installation of a part on an
     * equipment (if any) is being closed out by a new install — shared by
     * every jalur keluar (WO checklist, breakdown approval) so "which unit
     * is this replacing" is resolved the same way everywhere. An explicit
     * $oldInstallationId always wins (the approver/technician picked one
     * from an A/B/C list); otherwise this only auto-resolves when the
     * equipment has at most one active installation of that part — more
     * than one is ambiguous and the caller must supply a choice.
     *
     * $excludeIds lets a caller resolving several checks in one batch (see
     * TaskService::completeChecklist()) rule out installations it has
     * already claimed for an earlier check in that same batch, so two rows
     * planning brand-new units of the same part don't both try to claim the
     * one pre-existing installation as "the old one." Callers must resolve
     * every check against a snapshot taken *before* any install()/remove()
     * calls in the batch run — otherwise a sibling row's brand-new
     * installation (created moments earlier in the same request) would
     * itself look like a pre-existing "old" one to close out.
     *
     * An explicit $oldInstallationId is never trusted at face value — it must
     * actually belong to this equipment and part, and still be active
     * (removed_at IS NULL), locked for update so a concurrent call can't
     * claim the same row. Anything else (wrong equipment, wrong part,
     * already removed) is rejected outright rather than silently falling
     * back to "no old installation."
     *
     * @param  int[]  $excludeIds
     *
     * @throws AmbiguousInstallationException
     * @throws InvalidOldInstallationException
     */
    public function resolveActiveInstallation(Equipment $equipment, int $partId, ?int $oldInstallationId, array $excludeIds = []): ?PartInstallation
    {
        if ($oldInstallationId) {
            $installation = PartInstallation::whereKey($oldInstallationId)
                ->where('equipment_id', $equipment->id)
                ->where('part_id', $partId)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if (! $installation) {
                throw new InvalidOldInstallationException($oldInstallationId);
            }

            return $installation;
        }

        $active = $equipment->partInstallations()
            ->where('part_id', $partId)
            ->whereNull('removed_at')
            ->whereNotIn('id', $excludeIds)
            ->get();

        if ($active->count() > 1) {
            throw new AmbiguousInstallationException($partId);
        }

        return $active->first();
    }

    /**
     * Removing a unit always opens its repair record in the same breath —
     * every jalur keluar (manual "Lepas", breakdown replacement approval,
     * WO checklist replacement) funnels through here, so "Perlu Keputusan"
     * on the Repair Part board never needs a separate manual trigger.
     * $user records who actually pulled it (nullable only for any caller
     * that genuinely has no authenticated actor to attribute it to).
     */
    public function remove(PartInstallation $installation, ?User $user = null): PartInstallation
    {
        return DB::transaction(function () use ($installation, $user) {
            $installation->update([
                'removed_at' => now(),
                'removed_at_runtime_hours' => $installation->equipment->machine?->line?->runtime_hours ?? 0,
                'removed_by' => $user?->id,
            ]);

            if ($installation->partUnit) {
                $installation->partUnit->update(['status' => PartUnitStatus::PendingRepair]);

                PartRepair::create([
                    'part_unit_id' => $installation->part_unit_id,
                    'part_installation_id' => $installation->id,
                    'removed_at' => now(),
                    'disposition' => PartRepairDisposition::Pending,
                ]);
            }

            return $installation;
        });
    }

    /**
     * Move a repair record forward: in_repair while work is happening,
     * repaired once it's ready to go back into service (the unit becomes
     * "available" for the next install), or scrapped (terminal — the unit
     * can never be installed again). $repairCost is purely a history field
     * (how much this physical repair cost) — it never feeds into any
     * lifetime/budget formula, and is left untouched when not provided so a
     * later edit (e.g. correcting the ETA) doesn't accidentally blank it.
     */
    public function updateDisposition(
        PartRepair $repair,
        PartRepairDisposition $disposition,
        ?string $notes = null,
        ?string $estimatedCompletionDate = null,
        ?string $repairCost = null,
    ): PartRepair {
        return DB::transaction(function () use ($repair, $disposition, $notes, $estimatedCompletionDate, $repairCost) {
            $repair->update([
                'disposition' => $disposition,
                'notes' => $notes ?? $repair->notes,
                'estimated_completion_date' => $estimatedCompletionDate ?? $repair->estimated_completion_date,
                'repaired_at' => $disposition === PartRepairDisposition::Repaired ? now() : $repair->repaired_at,
                'repair_cost' => $repairCost ?? $repair->repair_cost,
            ]);

            $repair->partUnit->update([
                'status' => match ($disposition) {
                    PartRepairDisposition::Pending => PartUnitStatus::PendingRepair,
                    PartRepairDisposition::InRepair => PartUnitStatus::InRepair,
                    PartRepairDisposition::Repaired => PartUnitStatus::Available,
                    PartRepairDisposition::Scrapped => PartUnitStatus::Scrapped,
                },
            ]);

            return $repair;
        });
    }

    /**
     * Superadmin-only escape hatch for a mistaken "Lepas": undoes the
     * removal entirely rather than just deleting the repair record — puts
     * the installation back to active and the unit back InService, so the
     * Repair Part board and the equipment's install history both look like
     * the removal never happened. Refuses if the unit has since been
     * reinstalled somewhere else (repaired and put back into service),
     * since there'd be no single installation to restore it to.
     */
    public function undoRemoval(PartRepair $repair): void
    {
        DB::transaction(function () use ($repair) {
            $unit = $repair->partUnit;

            if ($unit && $unit->currentInstallation()) {
                abort(422, 'Unit ini sudah dipasang kembali di instalasi lain — tidak bisa dibatalkan.');
            }

            if ($repair->partInstallation) {
                $repair->partInstallation->update(['removed_at' => null, 'removed_at_runtime_hours' => null]);
                $unit?->update(['status' => PartUnitStatus::InService]);
            }

            $repair->delete();
        });
    }
}
