<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rack = strtoupper($this->faker->randomLetter()).$this->faker->numberBetween(1, 9);
        $bin = 'B'.$this->faker->numberBetween(1, 20);

        return [
            'branch_id' => Branch::factory(),
            'code' => "{$rack}-{$bin}",
            'rack' => $rack,
            'bin' => $bin,
            'description' => null,
            'is_active' => true,
        ];
    }
}
