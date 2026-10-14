<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use App\Models\TaskLibrary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task Library's checklist quantity is no longer a number the user types in
 * — it's always however many units of that part are currently (actively)
 * installed on the equipment (Equipment::activeInstallationCountForPart()),
 * computed server-side and never accepted from the request. Covers both the
 * "create library with parts bundled" path (TaskLibraryController::store())
 * and the "add one part to an existing library" path
 * (TaskLibraryPartController::store()).
 */
class TaskLibraryPartQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($branch->id);
    }

    private function installPart(Part $part, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            PartInstallation::create(['equipment_id' => $this->equipment->id, 'part_id' => $part->id]);
        }
    }

    public function test_adding_a_part_to_an_existing_library_uses_the_active_installation_count(): void
    {
        $part = Part::factory()->create();
        $this->installPart($part, 3);
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'Ganti Bearing',
            'code' => 'TL-1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/task-libraries/{$library->id}/parts", [
            'part_id' => $part->id,
        ]);

        $response->assertCreated();
        $this->assertSame(3, $response->json('data.quantity_required'));
    }

    public function test_a_client_supplied_quantity_is_ignored(): void
    {
        $part = Part::factory()->create();
        $this->installPart($part, 2);
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'Ganti Bearing',
            'code' => 'TL-1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/task-libraries/{$library->id}/parts", [
            'part_id' => $part->id,
            'quantity_required' => 999,
        ]);

        $response->assertCreated();
        $this->assertSame(2, $response->json('data.quantity_required'));
    }

    public function test_an_existing_unassigned_part_can_be_moved_under_a_condition_check(): void
    {
        $part = Part::factory()->create();
        $this->installPart($part);
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'PM Mingguan',
            'code' => 'TL-1',
            'is_active' => true,
        ]);
        $condition = $library->checklistItems()->create(['description' => 'Periksa kebocoran']);
        $libraryPart = $library->parts()->create([
            'part_id' => $part->id,
            'quantity_required' => 1,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/task-library-parts/{$libraryPart->id}", [
            'task_library_checklist_item_id' => $condition->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('task_library_parts', [
            'id' => $libraryPart->id,
            'task_library_checklist_item_id' => $condition->id,
        ]);
    }

    public function test_creating_a_library_with_bundled_parts_uses_the_active_installation_count(): void
    {
        $partA = Part::factory()->create();
        $partB = Part::factory()->create();
        $this->installPart($partA, 1);
        $this->installPart($partB, 4);

        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$this->equipment->id}/task-libraries", [
            'title' => 'PM Bulanan',
            'parts' => [
                ['part_id' => $partA->id, 'quantity_required' => 100],
                ['part_id' => $partB->id],
            ],
        ]);

        $response->assertCreated();
        $quantities = collect($response->json('data.parts'))->pluck('quantity_required', 'part_id');
        $this->assertSame(1, $quantities[$partA->id]);
        $this->assertSame(4, $quantities[$partB->id]);
    }
}
