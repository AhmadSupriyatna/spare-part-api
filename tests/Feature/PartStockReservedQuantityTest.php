<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Inventory Workspace's "Qty Direservasi" — computed, not stored: how much
 * of a PartStock is already spoken for by a still-open Task, from two
 * independent sources (see PartStockController::attachReservedQuantities()).
 */
class PartStockReservedQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private Part $part;

    private PartStock $stock;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->stock = PartStock::factory()->create([
            'part_id' => $this->part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 50,
        ]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_reserved_quantity_is_zero_with_no_open_tasks(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/part-stocks");

        $response->assertOk();
        $this->assertSame(0, $response->json('data.0.reserved_quantity'));
    }

    public function test_reserved_quantity_counts_a_plain_task_with_confirmed_quantity(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 3,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/part-stocks");

        $this->assertSame(3, $response->json('data.0.reserved_quantity'));
    }

    public function test_reserved_quantity_defaults_to_one_when_quantity_not_yet_confirmed(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::InProgress,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => null,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/part-stocks");

        $this->assertSame(1, $response->json('data.0.reserved_quantity'));
    }

    public function test_reserved_quantity_counts_open_checklist_rows_for_the_part(): void
    {
        $task = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
        ]);
        TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $this->part->id, 'quantity_planned' => 1]);
        TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $this->part->id, 'quantity_planned' => 1]);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/part-stocks");

        $this->assertSame(2, $response->json('data.0.reserved_quantity'));
    }

    public function test_completed_and_cancelled_tasks_are_not_counted_as_reserved(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Completed,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 5,
        ]);
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Cancelled,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 7,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/part-stocks");

        $this->assertSame(0, $response->json('data.0.reserved_quantity'));
    }

    public function test_reserved_quantity_is_also_exposed_on_the_single_stock_endpoint(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 4,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}");

        $response->assertOk()->assertJsonPath('data.reserved_quantity', 4);
    }
}
