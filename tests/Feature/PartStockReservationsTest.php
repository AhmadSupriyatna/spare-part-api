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

/** Part Detail's "Reservasi PM" — the actual upcoming Tasks behind reserved_quantity. */
class PartStockReservationsTest extends TestCase
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
        $this->stock = PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_lists_a_plain_task_reserving_this_stock(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 2,
            'title' => 'Ganti Seal',
            'due_date' => '2027-01-10',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/reservations");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ganti Seal', $response->json('data.0.title'));
        $this->assertSame(2, $response->json('data.0.quantity'));
    }

    public function test_lists_a_checklist_task_reserving_this_part(): void
    {
        $task = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::InProgress,
            'title' => 'Cek Rutin',
        ]);
        TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $this->part->id, 'quantity_planned' => 1]);
        TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $this->part->id, 'quantity_planned' => 1]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/reservations");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Cek Rutin', $response->json('data.0.title'));
        $this->assertSame(2, $response->json('data.0.quantity'));
    }

    public function test_does_not_list_completed_tasks(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Completed,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 5,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/reservations");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
