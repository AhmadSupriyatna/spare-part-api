<?php

namespace Database\Factories;

use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'code' => 'EQ-'.$this->faker->unique()->numberBetween(1, 999),
            'name' => $this->faker->randomElement(['Motor Penggerak', 'Gearbox', 'Unit Hidrolik', 'Unit Pneumatik', 'Panel Kontrol']),
            'category' => $this->faker->randomElement(['Mekanikal', 'Elektrikal', 'Hidrolik']),
            'is_active' => true,
        ];
    }
}
