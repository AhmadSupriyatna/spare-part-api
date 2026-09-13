<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\PartStock>
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
        $minimumStock = $this->faker->numberBetween(5, 20);
        $reorderPoint = $minimumStock + $this->faker->numberBetween(5, 15);
        $quantityOnHand = $this->faker->numberBetween(0, $reorderPoint + 30);

        return [
            'part_id' => Part::factory(),
            'branch_id' => Branch::factory(),
            'supplier_id' => null,
            'location_id' => null,
            'minimum_stock' => $minimumStock,
            'reorder_point' => $reorderPoint,
            'reorder_quantity' => $this->faker->numberBetween(20, 50),
            'unit_cost' => $this->faker->randomFloat(2, 5000, 500000),
            'quantity_on_hand' => $quantityOnHand,
        ];
    }
}
