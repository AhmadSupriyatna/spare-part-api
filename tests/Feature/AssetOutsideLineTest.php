<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidEquipmentHierarchyException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use App\Services\MaintenanceHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B "Asset Outside Line" — Equipment that belongs directly to a
 * Branch instead of a production Line -> Machine. Covers the new
 * machine_id/branch_id XOR invariant, Equipment::resolvedBranchId()/
 * hierarchicalCode(), the new /branches/{branch}/equipment endpoints, and
 * that every branch-authorization/scheduling/history path that previously
 * assumed equipment->machine->line now also works for an outside-line asset
 * without regressing production Equipment.
 */
class AssetOutsideLineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Branch $otherBranch;

    private User $admin;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->otherBranch = Branch::factory()->create();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);
    }

    private function makeProductionEquipment(?Branch $branch = null): Equipment
    {
        $line = ProductionLine::factory()->create(['branch_id' => ($branch ?? $this->branch)->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        return Equipment::factory()->create(['machine_id' => $machine->id]);
    }

    // --- Production Equipment: unchanged ---

    public function test_production_equipment_creation_still_works(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/machines/{$machine->id}/equipment", [
            'name' => 'Motor Penggerak',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.machine_id'));
        $this->assertNull($response->json('data.branch_id'));
    }

    public function test_production_equipment_has_machine_id_and_null_branch_id(): void
    {
        $equipment = $this->makeProductionEquipment();

        $this->assertNotNull($equipment->machine_id);
        $this->assertNull($equipment->branch_id);
    }

    public function test_production_hierarchical_code_is_unchanged(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'G']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => 'ST']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'MH']);

        $this->assertSame('G-ST-MH', $equipment->hierarchicalCode());
    }

    // --- Outside-line Equipment: creation ---

    public function test_outside_line_equipment_can_be_created_under_a_branch(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/equipment", [
            'name' => 'Panel Listrik Ruang AC',
        ]);

        $response->assertCreated();
        $this->assertSame($this->branch->id, $response->json('data.branch_id'));
        $this->assertNull($response->json('data.machine_id'));
        $this->assertNotEmpty($response->json('data.code'));
    }

    public function test_outside_line_equipment_has_branch_id_and_null_machine_id(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);

        $this->assertNull($equipment->machine_id);
        $this->assertSame($this->branch->id, $equipment->branch_id);
    }

    public function test_outside_line_equipment_does_not_accept_a_client_supplied_code(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/equipment", [
            'name' => 'Hand Pallet',
            'code' => 'HACKED',
        ]);

        $response->assertCreated();
        $this->assertNotSame('HACKED', $response->json('data.code'));
    }

    public function test_outside_line_equipment_store_requires_a_name(): void
    {
        $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/equipment", [])
            ->assertUnprocessable();
    }

    public function test_outside_line_hierarchical_code_uses_ol_prefix(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'code' => 'HANDPALLET']);

        $this->assertSame('OL-HANDPALLET', $equipment->hierarchicalCode());
    }

    public function test_outside_line_equipment_index_lists_only_that_branchs_assets(): void
    {
        Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'name' => 'Compressor']);
        Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->otherBranch->id, 'name' => 'Water Pump']);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/equipment");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Compressor', $names);
        $this->assertNotContains('Water Pump', $names);
    }

    public function test_creating_outside_line_equipment_does_not_modify_existing_production_data(): void
    {
        $production = $this->makeProductionEquipment();

        $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/equipment", [
            'name' => 'Compressor',
        ])->assertCreated();

        $production->refresh();
        $this->assertNotNull($production->machine_id);
        $this->assertNull($production->branch_id);
    }

    // --- XOR invariant ---

    public function test_equipment_cannot_have_both_machine_id_and_branch_id(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        $this->expectException(InvalidEquipmentHierarchyException::class);
        Equipment::factory()->create(['machine_id' => $machine->id, 'branch_id' => $this->branch->id]);
    }

    public function test_equipment_cannot_have_neither_machine_id_nor_branch_id(): void
    {
        $this->expectException(InvalidEquipmentHierarchyException::class);
        Equipment::factory()->create(['machine_id' => null, 'branch_id' => null]);
    }

    // --- resolvedBranchId() ---

    public function test_resolved_branch_id_for_production_equipment(): void
    {
        $equipment = $this->makeProductionEquipment();

        $this->assertSame($this->branch->id, $equipment->resolvedBranchId());
    }

    public function test_resolved_branch_id_for_outside_line_equipment(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);

        $this->assertSame($this->branch->id, $equipment->resolvedBranchId());
    }

    // --- BranchScopeResolver / EnsureBranchAccess ---

    public function test_branch_scope_resolver_allows_access_to_outside_line_equipment_in_users_branch(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->engineer)->getJson("/api/equipment/{$equipment->id}")->assertOk();
    }

    public function test_branch_scope_resolver_denies_access_to_outside_line_equipment_in_other_branch(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->engineer)->getJson("/api/equipment/{$equipment->id}")->assertForbidden();
    }

    public function test_branch_scope_resolver_still_works_for_production_equipment(): void
    {
        $equipment = $this->makeProductionEquipment($this->otherBranch);

        $this->actingAs($this->engineer)->getJson("/api/equipment/{$equipment->id}")->assertForbidden();
    }

    public function test_branch_access_denies_creating_outside_line_equipment_in_a_branch_the_user_cannot_access(): void
    {
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->otherBranch->id}/equipment", [
            'name' => 'Compressor',
        ])->assertForbidden();
    }

    // --- Task / Task Library / Planning resolve outside-line branch correctly ---

    public function test_task_library_can_be_created_for_outside_line_equipment(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'code' => 'COMPR']);

        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/task-libraries", [
            'title' => 'Cek Tekanan',
        ]);

        $response->assertCreated();
        $this->assertSame('OL-COMPR-01', $response->json('data.code'));
    }

    public function test_task_can_be_created_for_outside_line_equipment(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/tasks", [
            'title' => 'Cek Kebocoran',
            'cause' => 'kerusakan',
        ]);

        $response->assertCreated();
        $task = Task::find($response->json('data.id'));
        $this->assertSame(TaskStatus::Pending->value, $task->status->value);
    }

    public function test_completing_a_task_for_outside_line_equipment_does_not_crash_on_missing_runtime_hours(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Pending]);

        $response = $this->actingAs($this->engineer)->postJson("/api/tasks/{$task->id}/complete", [
            'notes' => 'Selesai',
        ]);

        $response->assertOk();
        $this->assertSame(TaskStatus::Completed->value, $task->fresh()->status->value);
    }

    // --- Maintenance history ---

    public function test_maintenance_history_includes_completed_task_for_outside_line_equipment(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'name' => 'Compressor']);
        Task::factory()->create([
            'equipment_id' => $equipment->id,
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);

        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);

        $this->assertTrue($rows->contains(fn (array $row) => $row['equipment'] === 'Compressor'));
    }

    // --- Existing Task Library / production behavior remains intact ---

    public function test_existing_production_task_library_listing_still_works(): void
    {
        $equipment = $this->makeProductionEquipment();
        $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/task-libraries", [
            'title' => 'Ganti Oli',
        ])->assertCreated();

        $response = $this->actingAs($this->admin)->getJson("/api/equipment/{$equipment->id}/task-libraries");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
