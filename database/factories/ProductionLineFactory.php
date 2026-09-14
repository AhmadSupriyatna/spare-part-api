<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ProductionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionLine>
 */
class ProductionLineFactory extends Factory
{
    protected $model = ProductionLine::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => 'LINE-'.$this->faker->unique()->numberBetween(1, 999),
            'name' => 'Line '.$this->faker->unique()->numberBetween(1, 999),
            'runtime_hours' => $this->faker->numberBetween(0, 500),
            'is_active' => true,
        ];
    }
}
