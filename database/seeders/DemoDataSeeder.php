<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Local/demo sample data: a couple of branches, each with its own suppliers
 * and rack/bin locations, plus a shared part catalog stocked at every branch.
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
        }
    }
}
