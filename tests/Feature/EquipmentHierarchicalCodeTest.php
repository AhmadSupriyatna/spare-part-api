<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\TaskLibrary;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Equipment Code Standardization & Hierarchical Identity — Line.code,
 * Machine.code and Equipment.code are each only unique WITHIN their own
 * parent (see the unique(['line_id'/'machine_id', 'code']) constraints), so
 * two equipment under different machines/lines can legitimately share the
 * same local `code` (confirmed in the real dev database: two different
 * equipment both coded "G"). TaskLibraryController::store() used to build
 * its globally-unique task_libraries.code from equipment.code alone, which
 * collided in exactly that case. It now uses Equipment::hierarchicalCode()
 * (Line.code-Machine.code-Equipment.code) instead.
 */
class EquipmentHierarchicalCodeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    /**
     * @return array{ProductionLine, Machine, Equipment}
     */
    private function makeEquipment(string $lineCode, string $machineCode, string $equipmentCode): array
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => $lineCode]);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => $machineCode]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => $equipmentCode]);

        return [$line, $machine, $equipment];
    }

    private function makeEquipmentOnLine(ProductionLine $line, string $machineCode, string $equipmentCode): Equipment
    {
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => $machineCode]);

        return Equipment::factory()->create(['machine_id' => $machine->id, 'code' => $equipmentCode]);
    }

    public function test_hierarchical_code_composes_line_machine_and_equipment_codes(): void
    {
        [, , $equipment] = $this->makeEquipment('G', 'ST', 'MH');

        $this->assertSame('G-ST-MH', $equipment->hierarchicalCode());
    }

    /**
     * The exact real-world scenario that broke (confirmed live in the dev
     * database): two equipment under the SAME line but DIFFERENT machines
     * both locally coded "G" — each is valid on its own (the DB constraint
     * only scopes equipment.code uniqueness per machine_id), but
     * task_libraries.code is globally unique, so their generated codes must
     * now differ.
     */
    public function test_duplicate_equipment_code_under_different_machines_no_longer_collides(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'G']);
        $equipmentA = $this->makeEquipmentOnLine($line, 'STERILIZER', 'G');
        $equipmentB = $this->makeEquipmentOnLine($line, 'ELECTRICAL', 'G');

        $this->assertSame('G', $equipmentA->code);
        $this->assertSame('G', $equipmentB->code);
        $this->assertNotSame($equipmentA->hierarchicalCode(), $equipmentB->hierarchicalCode());

        $libA = $this->actingAs($this->admin)->postJson(
            "/api/equipment/{$equipmentA->id}/task-libraries",
            ['title' => 'Ganti Seal'],
        )->assertCreated();

        $libB = $this->actingAs($this->admin)->postJson(
            "/api/equipment/{$equipmentB->id}/task-libraries",
            ['title' => 'Ganti Seal'],
        )->assertCreated();

        $this->assertSame('G-STERILIZER-G-01', $libA->json('data.code'));
        $this->assertSame('G-ELECTRICAL-G-01', $libB->json('data.code'));
        $this->assertNotSame($libA->json('data.code'), $libB->json('data.code'));
    }

    /** Sequence is scoped to the specific equipment_id, independent of any other equipment's counter. */
    public function test_sequence_is_scoped_per_equipment(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'X']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => 'MC1']);
        $equipmentA = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'EQ1']);
        $equipmentB = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'EQ2']);

        $a1 = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipmentA->id}/task-libraries", ['title' => 'A1'])->assertCreated();
        $a2 = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipmentA->id}/task-libraries", ['title' => 'A2'])->assertCreated();
        $b1 = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipmentB->id}/task-libraries", ['title' => 'B1'])->assertCreated();

        $this->assertSame('X-MC1-EQ1-01', $a1->json('data.code'));
        $this->assertSame('X-MC1-EQ1-02', $a2->json('data.code'));
        $this->assertSame('X-MC1-EQ2-01', $b1->json('data.code'));
    }

    /** task_libraries.code stays globally unique — the fix must not weaken that guarantee. */
    public function test_task_library_code_remains_globally_unique(): void
    {
        [, , $equipment] = $this->makeEquipment('Y', 'MC', 'EQ');

        TaskLibrary::create(['equipment_id' => $equipment->id, 'code' => 'Y-MC-EQ-01', 'title' => 'Manual dupe']);

        $this->expectException(QueryException::class);
        TaskLibrary::create(['equipment_id' => $equipment->id, 'code' => 'Y-MC-EQ-01', 'title' => 'Another']);
    }

    /**
     * Creating a NEW Task Library must never touch an existing one's code —
     * mirrors the real dev-DB rule ("existing Task Library codes must
     * remain unchanged").
     */
    public function test_creating_a_new_library_does_not_alter_an_existing_ones_code(): void
    {
        [, , $equipment] = $this->makeEquipment('Z', 'MC', 'EQ');

        $legacy = TaskLibrary::create([
            'equipment_id' => $equipment->id,
            'code' => 'TL-0099', // pre-hierarchical-scheme legacy-style code, untouched by this fix
            'title' => 'Legacy recipe',
        ]);

        $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/task-libraries", ['title' => 'New recipe'])->assertCreated();

        $this->assertSame('TL-0099', $legacy->fresh()->code);
    }

    public function test_line_code_is_trimmed_and_uppercased_on_create(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/lines", [
            'code' => '  g2  ',
            'name' => 'Line G2',
        ]);

        $response->assertCreated();
        $this->assertSame('G2', $response->json('data.code'));
    }

    public function test_machine_code_is_trimmed_and_uppercased_on_update(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'code' => 'OLD']);

        $response = $this->actingAs($this->admin)->putJson("/api/machines/{$machine->id}", [
            'code' => ' new-code ',
        ]);

        $response->assertOk();
        $this->assertSame('NEW-CODE', $response->json('data.code'));
    }

    public function test_equipment_code_is_trimmed_and_uppercased_on_create(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/machines/{$machine->id}/equipment", [
            'code' => ' mh ',
            'name' => 'Motor Head',
        ]);

        $response->assertCreated();
        $this->assertSame('MH', $response->json('data.code'));
    }

    /** hierarchical_code appears on EquipmentResource only when the machine relation is eager-loaded (matches machine_name/line_name's existing whenLoaded pattern). */
    public function test_hierarchical_code_is_exposed_on_equipment_show(): void
    {
        [, , $equipment] = $this->makeEquipment('G', 'ST', 'MH');

        $response = $this->actingAs($this->admin)->getJson("/api/equipment/{$equipment->id}");

        $response->assertOk()->assertJsonPath('data.hierarchical_code', 'G-ST-MH');
    }
}
