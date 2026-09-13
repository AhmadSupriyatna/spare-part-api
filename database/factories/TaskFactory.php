<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => null,
            'equipment_id' => Equipment::factory(),
            'assigned_to' => null,
            'title' => $this->faker->randomElement([
                'Perbaikan kebocoran oli',
                'Ganti part yang rusak',
                'Investigasi suara tidak normal',
            ]),
            'description' => null,
            'status' => TaskStatus::Pending,
            'cause' => 'kerusakan',
            'due_date' => now()->addDays($this->faker->numberBetween(1, 7)),
            'due_runtime_hours' => null,
        ];
    }
}
