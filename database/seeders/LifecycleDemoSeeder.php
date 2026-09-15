<?php

namespace Database\Seeders;

use App\Enums\PartRepairDisposition;
use App\Enums\PartUnitActionType;
use App\Enums\ReplacementRequestStatus;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\PartSupplier;
use App\Models\Supplier;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryPart;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\PartReplacementService;
use App\Services\PartUnitActionService;
use App\Services\PmSchedulingService;
use App\Services\TaskService;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Populates every module built on top of the original DemoDataSeeder scaffold
 * with believable, internally-consistent sample data: approved
 * suppliers, a Task Library (PM recipe) with both a completed and an
 * upcoming schedule, a handful of PartUnit life cycles at different stages
 * (in service, available for reinstall, near end of life, scrapped), and a
 * spread of breakdown/part-unit-action requests in every review state.
 *
 * Deliberately driven through the app's own Services (PartLifecycleService,
 * PartUnitActionService, PartReplacementService, PmSchedulingService,
 * TaskService) rather than raw Eloquent inserts, so the data respects the
 * same invariants real usage would (one active installation per unit, a
 * request never mutating state until "approved", etc.) — safe to run
 * against a database that already has real activity in it, not just a
 * fresh migrate.
 */
class LifecycleDemoSeeder extends Seeder
{
    public function run(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $unitActions = app(PartUnitActionService::class);
        $replacements = app(PartReplacementService::class);
        $pmScheduling = app(PmSchedulingService::class);
        $tasks = app(TaskService::class);

        $superadmin = User::where('email', 'superadmin@spareparts.test')->first() ?? User::first();
        $teknisi = User::where('email', 'teknisi@spareparts.test')->first();

        CompanySetting::current()->update(['name' => 'PT Sumber Makmur Sejahtera']);

        Branch::all()->each(function (Branch $branch) use ($lifecycle, $unitActions, $replacements, $pmScheduling, $tasks, $superadmin, $teknisi) {
            $equipmentList = Equipment::whereHas('machine.line', fn ($q) => $q->where('branch_id', $branch->id))
                ->with('machine.line')
                ->get();
            $parts = Part::inRandomOrder()->limit(6)->get();
            $suppliers = Supplier::where('branch_id', $branch->id)->get();

            if ($equipmentList->isEmpty() || $parts->count() < 5 || $suppliers->isEmpty()) {
                return;
            }

            $primaryEquipment = $equipmentList->first();
            $secondaryEquipment = $equipmentList->count() > 1 ? $equipmentList[1] : $primaryEquipment;

            // --- Approved supplier list for a few parts ---
            foreach ($parts->take(3) as $index => $part) {
                PartSupplier::firstOrCreate(
                    ['part_id' => $part->id, 'supplier_id' => $suppliers->random()->id],
                    [
                        'price' => fake()->numberBetween(50000, 500000),
                        'lead_time_days' => fake()->numberBetween(2, 14),
                        'is_preferred' => $index === 0,
                    ],
                );
            }

            // --- Task Library (PM recipe) with a checklist part, scheduled both in the past (completed) and upcoming ---
            $library = TaskLibrary::create([
                'equipment_id' => $primaryEquipment->id,
                'title' => 'Inspeksi & Pelumasan Bulanan',
                'description' => 'Cek kondisi umum, bersihkan, dan lumasi bagian yang bergerak.',
                'is_active' => true,
            ]);
            TaskLibraryPart::create([
                'task_library_id' => $library->id,
                'part_id' => $parts->first()->id,
                'quantity_required' => 1,
            ]);

            $pastTask = $pmScheduling->schedule($library, now()->subDays(20)->toDateString(), $teknisi?->id);
            $tasks->complete(task: $pastTask, user: $teknisi ?? $superadmin, notes: 'Selesai sesuai jadwal, kondisi normal.');
            $pmScheduling->schedule($library, now()->addDays(10)->toDateString(), $teknisi?->id);

            // --- Full life cycle: pasang -> lepas (via QR request) -> repair -> pasang lagi (via QR request) ---
            $cyclePart = $parts->get(1);
            $firstInstall = $lifecycle->install($primaryEquipment, ['part_id' => $cyclePart->id], $superadmin);
            $removeRequest = $unitActions->request(
                unit: $firstInstall->partUnit,
                action: PartUnitActionType::Remove,
                equipmentId: null,
                requestedByName: 'Dedi (Teknisi Lapangan)',
                notes: 'Bunyi kasar terdengar sejak kemarin.',
            );
            $unitActions->approve($removeRequest, $superadmin, 'Dicek, benar ada kerusakan pada bearing.');
            $repairedUnit = $firstInstall->partUnit->fresh();
            $repair = $lifecycle->sendToRepair($repairedUnit, $firstInstall->id, 'Ganti bearing yang aus.');
            $lifecycle->updateDisposition($repair, PartRepairDisposition::Repaired);
            $reinstallRequest = $unitActions->request(
                unit: $repairedUnit->fresh(),
                action: PartUnitActionType::Reinstall,
                equipmentId: $primaryEquipment->id,
                requestedByName: 'Dedi (Teknisi Lapangan)',
                notes: 'Sudah diperbaiki, pasang lagi.',
            );
            $unitActions->approve($reinstallRequest, $superadmin, 'OK, lanjut pasang.');

            // --- A repaired unit left "available" with a pending reinstall QR request awaiting approval ---
            $sparePart = $parts->get(2);
            $spareInstall = $lifecycle->install($secondaryEquipment, ['part_id' => $sparePart->id], $superadmin);
            $spareInstall = $lifecycle->remove($spareInstall);
            $spareRepair = $lifecycle->sendToRepair($spareInstall->partUnit, $spareInstall->id, 'Ganti seal, sudah dites.');
            $lifecycle->updateDisposition($spareRepair, PartRepairDisposition::Repaired);
            try {
                $unitActions->request(
                    unit: $spareInstall->partUnit->fresh(),
                    action: PartUnitActionType::Reinstall,
                    equipmentId: $secondaryEquipment->id,
                    requestedByName: 'Yanto (Teknisi Lapangan)',
                    notes: 'Sudah selesai diperbaiki dari bengkel rekanan.',
                );
            } catch (Throwable) {
                // fine to skip for demo data
            }

            // --- A unit near end of life (for the Part Lifetime alert page), with a pending removal QR request ---
            $wearPart = $parts->get(3);
            $wearPart->update(['estimated_lifetime_hours' => 100]);
            $wearInstall = $lifecycle->install($primaryEquipment, ['part_id' => $wearPart->id], $superadmin);
            $line = $primaryEquipment->machine->line;
            $wearInstall->update([
                'installed_at_runtime_hours' => max(0, $line->runtime_hours - 95),
            ]);
            try {
                $unitActions->request(
                    unit: $wearInstall->partUnit->fresh(),
                    action: PartUnitActionType::Remove,
                    equipmentId: null,
                    requestedByName: 'Dedi (Teknisi Lapangan)',
                    notes: 'Sesuai peringatan sistem, umur pakai sudah hampir habis.',
                );
            } catch (Throwable) {
            }

            // --- A scrapped unit, for a complete disposition spread on the Perbaikan Part page ---
            $scrapPart = $parts->get(4);
            $scrapInstall = $lifecycle->install($secondaryEquipment, ['part_id' => $scrapPart->id], $superadmin);
            $scrapInstall = $lifecycle->remove($scrapInstall);
            $scrapRepair = $lifecycle->sendToRepair($scrapInstall->partUnit, $scrapInstall->id, 'Retak di bagian rumah bearing.');
            $lifecycle->updateDisposition($scrapRepair, PartRepairDisposition::Scrapped, 'Tidak layak diperbaiki, dibuang sesuai kebijakan QA.');

            // --- Breakdown replacement requests: pending, approved, rejected ---
            // Picks a part actually installed on $primaryEquipment right now
            // (the cyclePart/wearPart installs above leave a couple active) —
            // consistent with the breakdown scan flow itself now reading
            // real installations instead of a separate BOM spec.
            $installedPartId = PartInstallation::where('equipment_id', $primaryEquipment->id)
                ->whereNull('removed_at')
                ->value('part_id');
            if ($installedPartId) {
                PartStock::where('part_id', $installedPartId)->where('branch_id', $branch->id)
                    ->update(['quantity_on_hand' => 50]);

                PartReplacementRequest::create([
                    'part_id' => $installedPartId,
                    'equipment_id' => $primaryEquipment->id,
                    'quantity_used' => 1,
                    'requested_by_name' => 'Joko (Operator Shift Malam)',
                    'reason' => 'Part patah saat produksi berjalan.',
                    'status' => ReplacementRequestStatus::Pending,
                ]);

                $approvedReq = PartReplacementRequest::create([
                    'part_id' => $installedPartId,
                    'equipment_id' => $primaryEquipment->id,
                    'quantity_used' => 1,
                    'requested_by_name' => 'Slamet (Operator Shift Pagi)',
                    'reason' => 'Aus, mesin bergetar.',
                    'status' => ReplacementRequestStatus::Pending,
                ]);
                try {
                    $replacements->approve($approvedReq, $superadmin, 'Sudah dicek, sesuai.');
                } catch (Throwable) {
                }

                $rejectedReq = PartReplacementRequest::create([
                    'part_id' => $installedPartId,
                    'equipment_id' => $primaryEquipment->id,
                    'quantity_used' => 5,
                    'requested_by_name' => 'Tono (Operator)',
                    'reason' => 'Ganti semua sekalian.',
                    'status' => ReplacementRequestStatus::Pending,
                ]);
                $replacements->reject($rejectedReq, $superadmin, 'Jumlah tidak wajar, cek dulu manual sebelum ganti massal.');
            }
        });
    }
}
