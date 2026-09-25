<?php

namespace Database\Factories;

use App\Enums\MaintenanceWorkOrderStatus;
use App\Models\Branch;
use App\Models\MaintenanceWorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWorkOrder>
 */
class MaintenanceWorkOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'line_id' => null,
            'planned_date' => null,
            'status' => MaintenanceWorkOrderStatus::Planning,
            'created_by' => null,
        ];
    }
}
