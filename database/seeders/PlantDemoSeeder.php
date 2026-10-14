<?php

namespace Database\Seeders;

use App\Enums\PartMinimumStockStrategy;
use App\Enums\PartReplacementStrategy;
use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Supplier;
use App\Models\TaskLibrary;
use App\Models\TaskLibraryChecklistItem;
use App\Models\TaskLibraryPart;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\PartReplacementService;
use App\Services\PmSchedulingService;
use App\Services\TaskService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A large, realistic "PT Sumber Makmur Sejahtera" demo scenario for live
 * demonstrations — deliberately much bigger than DemoDataSeeder's minimal
 * scaffold: 4 named production Lines (G/X/L/Y) each with a full packaging
 * line of machines, a spread of standalone Mesin Luar Line (forklift/hand
 * tools/AC), a wide spare-part catalog, and a Task Library + at least one
 * scheduled WO for every single equipment created — plus a generous,
 * realistic (not "literally every equipment") spread of Approval requests
 * across every status. Additive: safe to run against an already-seeded
 * database via `php artisan db:seed --class=PlantDemoSeeder`, never wired
 * into the default DatabaseSeeder chain.
 */
class PlantDemoSeeder extends Seeder
{
    private Collection $parts;

    public function run(): void
    {
        $branch = Branch::firstOrCreate(
            ['code' => 'PUSAT'],
            ['name' => 'Plant Pusat', 'is_active' => true],
        );
        $branch->update(['default_minimum_stock' => 10]);

        $superadmin = User::where('email', 'superadmin@spareparts.test')->first() ?? User::first();
        $engineer = User::where('email', 'engineer@spareparts.test')->first();
        $supervisor = User::where('email', 'supervisor@spareparts.test')->first();

        $suppliers = Supplier::where('branch_id', $branch->id)->get();
        if ($suppliers->isEmpty()) {
            $suppliers = Supplier::factory()->count(3)->for($branch)->create();
        }
        $locations = Location::where('branch_id', $branch->id)->get();
        if ($locations->isEmpty()) {
            $locations = Location::factory()->count(5)->for($branch)->create();
        }

        $this->parts = $this->seedParts($branch, $suppliers, $locations);

        $equipmentList = collect();
        $equipmentList = $equipmentList->merge($this->seedProductionLines($branch));
        $equipmentList = $equipmentList->merge($this->seedOutsideLineMachines($branch));

        $this->seedTaskLibrariesAndApprovals($equipmentList, $superadmin, $engineer, $supervisor);
    }

    /**
     * A wide, thematically-tagged spare-part catalog — 40 new Parts with a
     * mix of every replacement_strategy and minimum_stock_strategy, so the
     * dashboard/reports have real variety to show instead of one flat
     * strategy. Generous quantity_on_hand (30–150) so the Approval demo
     * requests below never hit InsufficientStockException.
     */
    private function seedParts(Branch $branch, Collection $suppliers, Collection $locations): Collection
    {
        $strategies = [
            PartReplacementStrategy::LifeBased,
            PartReplacementStrategy::FailureBased,
            PartReplacementStrategy::Scheduled,
        ];
        $minimumStrategies = [PartMinimumStockStrategy::Standard, PartMinimumStockStrategy::Installed];

        $parts = Part::factory()
            ->count(40)
            ->sequence(fn ($sequence) => [
                'replacement_strategy' => $strategies[$sequence->index % count($strategies)]->value,
                'minimum_stock_strategy' => $minimumStrategies[$sequence->index % count($minimumStrategies)]->value,
                'estimated_lifetime_hours' => $strategies[$sequence->index % count($strategies)] === PartReplacementStrategy::LifeBased
                    ? fake()->numberBetween(500, 4000)
                    : null,
            ])
            ->create();

        foreach ($parts as $part) {
            PartStock::factory()->for($part)->for($branch)->create([
                'supplier_id' => $suppliers->random()->id,
                'location_id' => $locations->random()->id,
                'quantity_on_hand' => fake()->numberBetween(30, 150),
            ]);
        }

        return $parts;
    }

    /**
     * 4 named production Lines, each with the same 8-machine packaging
     * line: Sterilizer, Checkweigher, Conveyor 1-5, Ink Code.
     *
     * @return Collection<int, Equipment>
     */
    private function seedProductionLines(Branch $branch): Collection
    {
        $machineNames = [
            'Sterilizer', 'Checkweigher',
            'Conveyor 1', 'Conveyor 2', 'Conveyor 3', 'Conveyor 4', 'Conveyor 5',
            'Ink Code',
        ];

        $equipmentList = collect();

        foreach (['G', 'X', 'L', 'Y'] as $letter) {
            $line = ProductionLine::create([
                'branch_id' => $branch->id,
                'code' => "LINE-{$letter}",
                'name' => "Line {$letter}",
                'runtime_hours' => fake()->numberBetween(1500, 9000),
                'avg_weekly_operating_hours' => fake()->numberBetween(70, 98),
                'is_active' => true,
            ]);

            foreach ($machineNames as $machineName) {
                $machine = Machine::create([
                    'line_id' => $line->id,
                    'code' => Machine::generateCode($machineName, $line->id),
                    'name' => $machineName,
                    'is_active' => true,
                ]);

                foreach ($this->equipmentTemplateFor($machineName) as $template) {
                    $equipmentList->push(Equipment::create([
                        'machine_id' => $machine->id,
                        'code' => Equipment::generateCode($template['name'], $machine->id),
                        'name' => $template['name'],
                        'category' => $template['category'],
                        'is_active' => true,
                    ]));
                }
            }
        }

        return $equipmentList;
    }

    /**
     * "Mesin Luar Line" — standalone assets with no production Line at all.
     *
     * @return Collection<int, Equipment>
     */
    private function seedOutsideLineMachines(Branch $branch): Collection
    {
        $machineNames = [
            'Jumbo Reel Truck', 'Hand Lift', 'Automatic Scrubbler',
            'Mesin Bor', 'Gerinda', 'Heat Gun',
            'AC Genba Office', 'AC LAB 1', 'AC LAB 2', 'AC LAB 3', 'AC Office Atas',
        ];

        $equipmentList = collect();

        foreach ($machineNames as $machineName) {
            $machine = Machine::create([
                'branch_id' => $branch->id,
                'code' => Machine::generateCodeForBranch($machineName, $branch->id),
                'name' => $machineName,
                'is_active' => true,
            ]);

            foreach ($this->equipmentTemplateFor($machineName) as $template) {
                $equipmentList->push(Equipment::create([
                    'machine_id' => $machine->id,
                    'code' => Equipment::generateCode($template['name'], $machine->id),
                    'name' => $template['name'],
                    'category' => $template['category'],
                    'is_active' => true,
                ]));
            }
        }

        return $equipmentList;
    }

    /**
     * @return array<int, array{name: string, category: string, parts: string[]}>
     */
    private function equipmentTemplateFor(string $machineName): array
    {
        return match (true) {
            $machineName === 'Sterilizer' => [
                ['name' => 'Chamber Pemanas', 'category' => 'Sterilizer', 'parts' => ['Gasket Sterilizer', 'Sensor Suhu PT100']],
                ['name' => 'Katup Tekanan', 'category' => 'Sterilizer', 'parts' => ['Solenoid Valve', 'Filter Regulator Udara']],
                ['name' => 'Kaca Indikator Level', 'category' => 'Sterilizer', 'parts' => ['Kaca Indikator Level Sterilizer']],
                ['name' => 'Motor Penggerak Chamber', 'category' => 'Sterilizer', 'parts' => ['Motor Servo AC', 'Bearing 6205']],
            ],
            $machineName === 'Checkweigher' => [
                ['name' => 'Load Cell Timbangan', 'category' => 'Checkweigher', 'parts' => ['Load Cell 5kg']],
                ['name' => 'Conveyor Belt Timbangan', 'category' => 'Checkweigher', 'parts' => ['V-Belt', 'Bearing 6205']],
                ['name' => 'Rejector Arm', 'category' => 'Checkweigher', 'parts' => ['Cylinder Pneumatik', 'Solenoid Valve']],
                ['name' => 'Panel HMI', 'category' => 'Checkweigher', 'parts' => ['Kontaktor Magnetik', 'MCB 3 Phase']],
            ],
            str_starts_with($machineName, 'Conveyor') => [
                ['name' => 'Motor Penggerak', 'category' => 'Conveyor', 'parts' => ['Motor Servo AC', 'Gear Reducer']],
                ['name' => 'Belt Conveyor', 'category' => 'Conveyor', 'parts' => ['V-Belt', 'Rantai Conveyor']],
                ['name' => 'Roller & Bearing', 'category' => 'Conveyor', 'parts' => ['Roller Conveyor Idler', 'Bantalan UCP 205']],
                ['name' => 'Gearbox', 'category' => 'Conveyor', 'parts' => ['Gear Reducer', 'Sprocket Conveyor']],
            ],
            $machineName === 'Ink Code' => [
                ['name' => 'Print Head', 'category' => 'Ink Coder', 'parts' => ['Print Head Inkjet']],
                ['name' => 'Ink Cartridge Unit', 'category' => 'Ink Coder', 'parts' => ['Ink Cartridge Continuous Printer', 'Filter Ink Coder']],
                ['name' => 'Sensor Deteksi Produk', 'category' => 'Ink Coder', 'parts' => ['Sensor Proximity Induktif']],
            ],
            $machineName === 'Jumbo Reel Truck' => [
                ['name' => 'Roda & Ban', 'category' => 'Material Handling', 'parts' => ['Roda Hand Lift Poly']],
                ['name' => 'Sistem Hidrolik Lift', 'category' => 'Material Handling', 'parts' => ['Selang Hidrolik Forklift', 'Oli Hidrolik']],
                ['name' => 'Sistem Rem', 'category' => 'Material Handling', 'parts' => ['Oli Hidrolik']],
            ],
            $machineName === 'Hand Lift' => [
                ['name' => 'Roda', 'category' => 'Material Handling', 'parts' => ['Roda Hand Lift Poly']],
                ['name' => 'Pompa Hidrolik', 'category' => 'Material Handling', 'parts' => ['Pompa Hidrolik Gear Pump', 'Oli Hidrolik']],
                ['name' => 'Garpu', 'category' => 'Material Handling', 'parts' => ['Garpu Forklift']],
            ],
            $machineName === 'Automatic Scrubbler' => [
                ['name' => 'Motor Brush', 'category' => 'Cleaning', 'parts' => ['Brush Scrubbler Nylon']],
                ['name' => 'Baterai', 'category' => 'Cleaning', 'parts' => ['Baterai Scrubbler 24V']],
                ['name' => 'Squeegee & Water Tank', 'category' => 'Cleaning', 'parts' => ['Squeegee Blade Scrubbler']],
            ],
            $machineName === 'Mesin Bor' => [
                ['name' => 'Motor Bor', 'category' => 'Peralatan Tangan', 'parts' => ['Carbon Brush Bor']],
                ['name' => 'Chuck', 'category' => 'Peralatan Tangan', 'parts' => ['Chuck Bor 13mm']],
                ['name' => 'Kabel Power', 'category' => 'Peralatan Tangan', 'parts' => ['Kabel Power Universal']],
            ],
            $machineName === 'Gerinda' => [
                ['name' => 'Motor Gerinda', 'category' => 'Peralatan Tangan', 'parts' => ['Carbon Brush Bor']],
                ['name' => 'Mata Gerinda', 'category' => 'Peralatan Tangan', 'parts' => ['Mata Gerinda Potong', 'Mata Gerinda Asah']],
                ['name' => 'Kabel Power', 'category' => 'Peralatan Tangan', 'parts' => ['Kabel Power Universal']],
            ],
            $machineName === 'Heat Gun' => [
                ['name' => 'Elemen Pemanas', 'category' => 'Peralatan Tangan', 'parts' => ['Elemen Pemanas Heat Gun']],
                ['name' => 'Motor Kipas', 'category' => 'Peralatan Tangan', 'parts' => ['Kabel Power Universal']],
            ],
            str_starts_with($machineName, 'AC ') => [
                ['name' => 'Unit Compressor', 'category' => 'AC', 'parts' => ['Kapasitor AC', 'Kondensor AC']],
                ['name' => 'Evaporator Indoor', 'category' => 'AC', 'parts' => ['Evaporator Coil AC', 'Filter Udara AC']],
                ['name' => 'Thermostat & Remote', 'category' => 'AC', 'parts' => ['Thermostat Digital AC']],
            ],
            default => [
                ['name' => 'Unit Utama', 'category' => 'Umum', 'parts' => []],
            ],
        };
    }

    /**
     * The first Part whose name contains any of the given (case-insensitive)
     * substrings, falling back to a random Part from the whole demo catalog
     * so every equipment always gets something plausible installed.
     *
     * @param  string[]  $nameHints
     */
    private function pickPart(array $nameHints): Part
    {
        foreach ($nameHints as $hint) {
            $match = $this->parts->first(fn (Part $part) => str_contains(mb_strtolower($part->name), mb_strtolower($hint)));
            if ($match) {
                return $match;
            }
        }

        return $this->parts->random();
    }

    /**
     * One Task Library (with a checklist item + the equipment's own
     * installed part as the planned checklist part) and at least one
     * scheduled WO for every equipment. A generous but not exhaustive
     * spread of Approval requests (pending/approved/rejected) — every 4th
     * equipment — keeps the Approval board realistically populated rather
     * than absurdly flooded with 150 open requests at once.
     */
    private function seedTaskLibrariesAndApprovals(
        Collection $equipmentList,
        ?User $superadmin,
        ?User $engineer,
        ?User $supervisor,
    ): void {
        $lifecycle = app(PartLifecycleService::class);
        $pmScheduling = app(PmSchedulingService::class);
        $taskService = app(TaskService::class);
        $replacements = app(PartReplacementService::class);
        $assignee = $engineer ?? $superadmin;
        $reviewer = $supervisor ?? $superadmin;

        foreach ($equipmentList as $index => $equipment) {
            $template = collect($this->equipmentTemplateFor($equipment->name))->firstWhere('name', $equipment->name);
            $part = $this->pickPart($template['parts'] ?? []);

            if ($superadmin) {
                try {
                    $lifecycle->install($equipment, ['part_id' => $part->id], $superadmin);
                } catch (Throwable) {
                    // Fine to skip for demo data — e.g. an already-installed slot.
                }
            }

            $library = TaskLibrary::create([
                'equipment_id' => $equipment->id,
                'title' => "Inspeksi Rutin {$equipment->name}",
                'description' => "Pemeriksaan kondisi umum dan pelumasan/pembersihan {$equipment->name}.",
                'is_active' => true,
            ]);
            TaskLibraryPart::create([
                'task_library_id' => $library->id,
                'part_id' => $part->id,
                'quantity_required' => 1,
            ]);
            TaskLibraryChecklistItem::create([
                'task_library_id' => $library->id,
                'description' => "Periksa kondisi {$equipment->name}, bersihkan dari debu/kotoran.",
                'order' => 1,
            ]);

            $pmScheduling->schedule($library, now()->addDays(fake()->numberBetween(1, 30))->toDateString(), $assignee?->id);

            // Every 3rd equipment also gets a completed past WO, for Riwayat/Laporan data.
            if ($index % 3 === 0) {
                $past = $pmScheduling->schedule($library, now()->subDays(fake()->numberBetween(3, 60))->toDateString(), $assignee?->id);
                try {
                    $taskService->complete(task: $past, user: $assignee ?? $superadmin, notes: 'Kondisi baik, tidak ada temuan.');
                } catch (Throwable) {
                }
            }

            // Every 4th equipment gets an Approval request, cycling pending/approved/rejected.
            if ($index % 4 === 0 && $reviewer) {
                $eventType = ReplacementRequestEventType::fromReplacementStrategy($part->replacement_strategy);
                $slot = $index % 12;

                $request = PartReplacementRequest::create([
                    'part_id' => $part->id,
                    'equipment_id' => $equipment->id,
                    'quantity_used' => 1,
                    'event_type' => $eventType->value,
                    'requested_by_name' => fake()->randomElement(['Joko (Operator Shift Malam)', 'Slamet (Operator Shift Pagi)', 'Dedi (Teknisi Lapangan)', 'Yanto (Operator)']),
                    'reason' => fake()->randomElement(['Part aus, mesin bergetar.', 'Rusak saat produksi berjalan.', 'Sesuai jadwal inspeksi ditemukan cacat.', 'Berisik dan panas berlebih.']),
                    'status' => ReplacementRequestStatus::Pending,
                ]);

                try {
                    if ($slot % 3 === 1) {
                        $replacements->approve($request, $reviewer, 'Sudah dicek, sesuai — disetujui.');
                    } elseif ($slot % 3 === 2) {
                        $replacements->reject($request, $reviewer, 'Belum sesuai prosedur, cek ulang dulu.');
                    }
                } catch (Throwable) {
                    // Fine to leave as pending for demo data if approve/reject can't apply.
                }
            }
        }
    }
}
