<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Machine Detail's "Riwayat PM" — every Task ever created for a Machine's
 * equipment, any status, paginated. Unlike woReportForMachine() (an
 * explicit date-range report), this is the full unfiltered history, so a
 * long-lived Machine doesn't dump hundreds of rows in one response.
 */
class MachineTaskHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
    }

    public function test_history_includes_every_status_and_paginates(): void
    {
        $line = ProductionLine::factory()->create();
        $this->admin->branches()->attach($line->branch_id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        Task::factory()->count(20)->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Completed]);
        Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Cancelled, 'title' => 'Dibatalkan']);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/task-history");

        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
        $this->assertSame(21, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));

        $allTitles = collect($response->json('data'))->pluck('title')->all();
        $secondPage = $this->actingAs($this->admin)
            ->getJson("/api/machines/{$machine->id}/task-history?page=2")
            ->json('data');
        $allTitles = array_merge($allTitles, collect($secondPage)->pluck('title')->all());
        $this->assertContains('Dibatalkan', $allTitles);
    }

    public function test_history_does_not_include_a_different_machines_tasks(): void
    {
        $line = ProductionLine::factory()->create();
        $this->admin->branches()->attach($line->branch_id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $otherMachine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        Task::factory()->create(['equipment_id' => $equipment->id, 'title' => 'Mine']);
        Task::factory()->create(['equipment_id' => $otherEquipment->id, 'title' => 'Not Mine']);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/task-history");

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Not Mine', $titles);
    }
}
