<?php

namespace App\Services;

use App\Enums\PartRepairDisposition;
use App\Enums\PartUnitStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\PartUnitAlreadyInstalledException;
use App\Models\Equipment;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartUnit;
use App\Models\User;
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
            $currentRuntimeHours = $equipment->machine->line->runtime_hours;
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
     * @param  int[]  $excludeIds
     *
     * @throws AmbiguousInstallationException
     */
    public function resolveActiveInstallation(Equipment $equipment, int $partId, ?int $oldInstallationId, array $excludeIds = []): ?PartInstallation
    {
        if ($oldInstallationId) {
            return PartInstallation::whereKey($oldInstallationId)->first();
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

    public function remove(PartInstallation $installation): PartInstallation
    {
        return DB::transaction(function () use ($installation) {
            $installation->update([
                'removed_at' => now(),
                'removed_at_runtime_hours' => $installation->equipment->machine->line->runtime_hours,
            ]);

            $installation->partUnit?->update(['status' => PartUnitStatus::PendingRepair]);

            return $installation;
        });
    }

    /**
     * Open a repair record for a removed unit — defaults to linking the
     * unit's most recently ended installation cycle if none is given.
     */
    public function sendToRepair(
        PartUnit $unit,
        ?int $partInstallationId,
        ?string $notes,
        ?string $estimatedCompletionDate = null,
    ): PartRepair {
        return DB::transaction(function () use ($unit, $partInstallationId, $notes, $estimatedCompletionDate) {
            $partInstallationId ??= $unit->installations()
                ->whereNotNull('removed_at')
                ->latest('removed_at')
                ->value('id');

            $repair = PartRepair::create([
                'part_unit_id' => $unit->id,
                'part_installation_id' => $partInstallationId,
                'removed_at' => now(),
                'estimated_completion_date' => $estimatedCompletionDate,
                'disposition' => PartRepairDisposition::Pending,
                'notes' => $notes,
            ]);

            $unit->update(['status' => PartUnitStatus::PendingRepair]);

            return $repair;
        });
    }

    /**
     * Move a repair record forward: in_repair while work is happening,
     * repaired once it's ready to go back into service (the unit becomes
     * "available" for the next install), or scrapped (terminal — the unit
     * can never be installed again).
     */
    public function updateDisposition(
        PartRepair $repair,
        PartRepairDisposition $disposition,
        ?string $notes = null,
        ?string $estimatedCompletionDate = null,
    ): PartRepair {
        return DB::transaction(function () use ($repair, $disposition, $notes, $estimatedCompletionDate) {
            $repair->update([
                'disposition' => $disposition,
                'notes' => $notes ?? $repair->notes,
                'estimated_completion_date' => $estimatedCompletionDate ?? $repair->estimated_completion_date,
                'repaired_at' => $disposition === PartRepairDisposition::Repaired ? now() : $repair->repaired_at,
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
}
