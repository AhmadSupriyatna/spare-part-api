<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Part;
use App\Models\PartStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartStock>
 */
class PartStockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_id' => Part::factory(),
            'branch_id' => Branch::factory(),
            'supplier_id' => null,
            'location_id' => null,
            'unit_cost' => $this->faker->randomFloat(2, 5000, 500000),
            'quantity_on_hand' => $this->faker->numberBetween(0, 50),
        ];
    }
}
