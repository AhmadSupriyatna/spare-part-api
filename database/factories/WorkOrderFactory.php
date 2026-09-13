<?php

namespace Database\Factories;

use App\Enums\ScheduleType;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduleType = $this->faker->randomElement([ScheduleType::Calendar, ScheduleType::Runtime]);

        return [
            'equipment_id' => Equipment::factory(),
            'part_id' => null,
            'title' => $this->faker->randomElement([
                'Ganti oli pelumas',
                'Cek dan kencangkan baut',
                'Ganti bearing',
                'Kalibrasi sensor',
                'Bersihkan filter udara',
            ]),
            'description' => null,
            'schedule_type' => $scheduleType,
            'interval_days' => $scheduleType === ScheduleType::Calendar ? $this->faker->randomElement([30, 90, 180]) : null,
            'interval_hours' => $scheduleType === ScheduleType::Runtime ? $this->faker->randomElement([500, 1000, 2000]) : null,
            'is_active' => true,
        ];
    }
}
