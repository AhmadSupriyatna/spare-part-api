<?php

namespace App\Services;

use App\Enums\PartRepairDisposition;
use App\Enums\PartUnitStatus;
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
     * @param  array{part_id: int, part_unit_id?: int|null, installed_at?: string|null, notes?: string|null}  $data
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

            $installation = $equipment->partInstallations()->create([
                'part_id' => $data['part_id'],
                'part_unit_id' => $unit->id,
                'installed_at' => $data['installed_at'] ?? now(),
                'installed_at_runtime_hours' => $currentRuntimeHours,
                'installed_by' => $user->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $pendingRepair?->update(['reinstalled_installation_id' => $installation->id]);

            return $installation;
        });
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
    public function sendToRepair(PartUnit $unit, ?int $partInstallationId, ?string $notes): PartRepair
    {
        return DB::transaction(function () use ($unit, $partInstallationId, $notes) {
            $partInstallationId ??= $unit->installations()
                ->whereNotNull('removed_at')
                ->latest('removed_at')
                ->value('id');

            $repair = PartRepair::create([
                'part_unit_id' => $unit->id,
                'part_installation_id' => $partInstallationId,
                'removed_at' => now(),
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
    public function updateDisposition(PartRepair $repair, PartRepairDisposition $disposition, ?string $notes = null): PartRepair
    {
        return DB::transaction(function () use ($repair, $disposition, $notes) {
            $repair->update([
                'disposition' => $disposition,
                'notes' => $notes ?? $repair->notes,
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
