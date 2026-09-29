<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InvalidMachineHierarchyException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Mesin Luar Line" — a Machine that belongs directly to a Branch instead
 * of a production Line, for standalone assets like a compressor room or
 * genset. Mirrors AssetOutsideLineTest one level up: the new
 * line_id/branch_id XOR invariant, Machine::resolvedBranchId(), the new
 * /branches/{branch}/machines endpoints, and that Equipment attached to an
 * outside-line Machine still resolves its branch and hierarchical code
 * correctly.
 */
class MachineOutsideLineTest extends TestCase
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

    // --- Production Machine: unchanged ---

    public function test_production_machine_creation_still_works(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/lines/{$line->id}/machines", [
            'name' => 'Mesin Press',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.line_id'));
        $this->assertNull($response->json('data.branch_id'));
    }

    // --- Outside-line Machine: creation ---

    public function test_outside_line_machine_can_be_created_under_a_branch(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/machines", [
            'name' => 'Compressor Room',
        ]);

        $response->assertCreated();
        $this->assertSame($this->branch->id, $response->json('data.branch_id'));
        $this->assertNull($response->json('data.line_id'));
        $this->assertNotEmpty($response->json('data.code'));
    }

    public function test_outside_line_machine_does_not_accept_a_client_supplied_code(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/machines", [
            'name' => 'Genset',
            'code' => 'HACKED',
        ]);

        $response->assertCreated();
        $this->assertNotSame('HACKED', $response->json('data.code'));
    }

    public function test_outside_line_machine_store_requires_a_name(): void
    {
        $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/machines", [])
            ->assertUnprocessable();
    }

    public function test_outside_line_machine_index_lists_only_that_branchs_machines(): void
    {
        Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id, 'name' => 'Compressor']);
        Machine::factory()->create(['line_id' => null, 'branch_id' => $this->otherBranch->id, 'name' => 'Water Treatment']);

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/machines");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Compressor', $names);
        $this->assertNotContains('Water Treatment', $names);
    }

    // --- XOR invariant ---

    public function test_machine_cannot_have_both_line_id_and_branch_id(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);

        $this->expectException(InvalidMachineHierarchyException::class);
        Machine::factory()->create(['line_id' => $line->id, 'branch_id' => $this->branch->id]);
    }

    public function test_machine_cannot_have_neither_line_id_nor_branch_id(): void
    {
        $this->expectException(InvalidMachineHierarchyException::class);
        Machine::factory()->create(['line_id' => null, 'branch_id' => null]);
    }

    // --- resolvedBranchId() ---

    public function test_resolved_branch_id_for_production_machine(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        $this->assertSame($this->branch->id, $machine->resolvedBranchId());
    }

    public function test_resolved_branch_id_for_outside_line_machine(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id]);

        $this->assertSame($this->branch->id, $machine->resolvedBranchId());
    }

    // --- Equipment under an outside-line Machine ---

    public function test_equipment_under_an_outside_line_machine_resolves_its_branch(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $this->assertSame($this->branch->id, $equipment->resolvedBranchId());
    }

    public function test_equipment_under_an_outside_line_machine_has_a_two_segment_hierarchical_code(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id, 'code' => 'COMPR']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'MOTOR']);

        $this->assertSame('COMPR-MOTOR', $equipment->hierarchicalCode());
    }

    public function test_equipment_under_a_production_machine_keeps_its_three_segment_hierarchical_code(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'G']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => 'ST']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'MH']);

        $this->assertSame('G-ST-MH', $equipment->hierarchicalCode());
    }

    // --- BranchScopeResolver / EnsureBranchAccess ---

    public function test_branch_scope_resolver_allows_access_to_outside_line_machine_in_users_branch(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id]);

        $this->actingAs($this->engineer)->getJson("/api/machines/{$machine->id}")->assertOk();
    }

    public function test_branch_scope_resolver_denies_access_to_outside_line_machine_in_other_branch(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->engineer)->getJson("/api/machines/{$machine->id}")->assertForbidden();
    }

    public function test_branch_access_denies_creating_outside_line_machine_in_a_branch_the_user_cannot_access(): void
    {
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->otherBranch->id}/machines", [
            'name' => 'Compressor',
        ])->assertForbidden();
    }
}
