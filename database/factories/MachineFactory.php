<?php

namespace Database\Factories;

use App\Models\ProductionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Machine>
 */
class MachineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line_id' => ProductionLine::factory(),
            'code' => 'MC-'.$this->faker->unique()->numberBetween(1, 999),
            'name' => $this->faker->randomElement(['Mesin Press', 'Mesin Cetak', 'Conveyor', 'Mesin Bubut', 'Mesin CNC']),
            'category' => $this->faker->randomElement(['Produksi', 'Pengemasan', 'Material Handling']),
            'is_active' => true,
        ];
    }
}
