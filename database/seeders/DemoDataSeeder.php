<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;

/**
 * Local/demo sample data: a couple of branches, each with its own suppliers,
 * rack/bin locations, a shared part catalog stocked at every branch, and a
 * small line > machine > equipment hierarchy with maintenance work orders.
 * Not intended for production seeding.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $branches = collect([
            ['code' => 'PUSAT', 'name' => 'Cabang Pusat'],
            ['code' => 'JKT', 'name' => 'Cabang Jakarta'],
            ['code' => 'SBY', 'name' => 'Cabang Surabaya'],
        ])->map(fn (array $attributes) => Branch::create([...$attributes, 'is_active' => true]));

        $parts = Part::factory()->count(15)->create();

        foreach ($branches as $branch) {
            $suppliers = Supplier::factory()->count(3)->for($branch)->create();
            $locations = Location::factory()->count(5)->for($branch)->create();

            foreach ($parts as $part) {
                PartStock::factory()->for($part)->for($branch)->create([
                    'supplier_id' => $suppliers->random()->id,
                    'location_id' => $locations->random()->id,
                ]);
            }

            $line = ProductionLine::factory()->for($branch, 'branch')->create();

            $machines = Machine::factory()->count(2)->for($line, 'line')->create();

            $firstEquipment = null;

            foreach ($machines as $machine) {
                $equipmentUnits = Equipment::factory()->count(2)->for($machine)->create();
                $firstEquipment ??= $equipmentUnits->first();

                foreach ($equipmentUnits as $equipment) {
                    WorkOrder::factory()->for($equipment)->create();
                }
            }

            // One reactive/unscheduled task per branch, e.g. a breakdown found during inspection.
            Task::factory()->for($firstEquipment, 'equipment')->create();
        }
    }
}
