<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Machine.code and Equipment.code are now system-generated from `name`
 * (Line.code stays the only manually-entered one) — neither request accepts
 * a client-supplied `code` any more, and an existing code must never change
 * just because the name was edited.
 */
class MachineEquipmentCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'G']);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_machine_code_is_generated_from_name(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/lines/{$this->line->id}/machines", [
            'name' => 'Mesin Press Hidrolik',
        ]);

        $response->assertCreated();
        $this->assertSame('MESIN-PRESS-HIDROLIK', $response->json('data.code'));
    }

    /** A client-supplied `code` is silently ignored — it isn't a validated field at all. */
    public function test_client_supplied_machine_code_is_ignored(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/lines/{$this->line->id}/machines", [
            'name' => 'Mesin Press Hidrolik',
            'code' => 'HACKED',
        ]);

        $response->assertCreated();
        $this->assertSame('MESIN-PRESS-HIDROLIK', $response->json('data.code'));
    }

    public function test_duplicate_machine_name_on_the_same_line_gets_a_disambiguated_code(): void
    {
        $first = $this->actingAs($this->admin)->postJson("/api/lines/{$this->line->id}/machines", ['name' => 'Conveyor'])->assertCreated();
        $second = $this->actingAs($this->admin)->postJson("/api/lines/{$this->line->id}/machines", ['name' => 'Conveyor'])->assertCreated();

        $this->assertSame('CONVEYOR', $first->json('data.code'));
        $this->assertSame('CONVEYOR-2', $second->json('data.code'));
    }

    public function test_updating_machine_name_does_not_change_its_existing_code(): void
    {
        $machine = Machine::factory()->create(['line_id' => $this->line->id, 'code' => 'ORIGINAL-CODE']);

        $response = $this->actingAs($this->admin)->putJson("/api/machines/{$machine->id}", [
            'name' => 'Nama Baru Sekali',
        ]);

        $response->assertOk();
        $this->assertSame('ORIGINAL-CODE', $response->json('data.code'));
        $this->assertSame('ORIGINAL-CODE', $machine->fresh()->code);
    }

    /** Sending `code` on update has no effect — it isn't a validated field. */
    public function test_client_supplied_code_on_machine_update_is_ignored(): void
    {
        $machine = Machine::factory()->create(['line_id' => $this->line->id, 'code' => 'ORIGINAL-CODE']);

        $this->actingAs($this->admin)->putJson("/api/machines/{$machine->id}", [
            'code' => 'HACKED',
        ])->assertOk();

        $this->assertSame('ORIGINAL-CODE', $machine->fresh()->code);
    }

    public function test_equipment_code_is_generated_from_name(): void
    {
        $machine = Machine::factory()->create(['line_id' => $this->line->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/machines/{$machine->id}/equipment", [
            'name' => 'Motor Penggerak Utama',
        ]);

        $response->assertCreated();
        $this->assertSame('MOTOR-PENGGERAK-UTAMA', $response->json('data.code'));
    }

    public function test_duplicate_equipment_name_on_the_same_machine_gets_a_disambiguated_code(): void
    {
        $machine = Machine::factory()->create(['line_id' => $this->line->id]);

        $first = $this->actingAs($this->admin)->postJson("/api/machines/{$machine->id}/equipment", ['name' => 'Sensor'])->assertCreated();
        $second = $this->actingAs($this->admin)->postJson("/api/machines/{$machine->id}/equipment", ['name' => 'Sensor'])->assertCreated();

        $this->assertSame('SENSOR', $first->json('data.code'));
        $this->assertSame('SENSOR-2', $second->json('data.code'));
    }

    public function test_updating_equipment_name_does_not_change_its_existing_code(): void
    {
        $machine = Machine::factory()->create(['line_id' => $this->line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'code' => 'ORIGINAL-CODE']);

        $response = $this->actingAs($this->admin)->putJson("/api/equipment/{$equipment->id}", [
            'name' => 'Nama Baru Sekali',
        ]);

        $response->assertOk();
        $this->assertSame('ORIGINAL-CODE', $response->json('data.code'));
        $this->assertSame('ORIGINAL-CODE', $equipment->fresh()->code);
    }

    /** Same base slug on a DIFFERENT line/machine is untouched — scoping matches the unique constraint exactly. */
    public function test_same_name_on_a_different_line_does_not_get_disambiguated(): void
    {
        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'code' => 'H']);

        $onLineG = $this->actingAs($this->admin)->postJson("/api/lines/{$this->line->id}/machines", ['name' => 'Conveyor'])->assertCreated();
        $onLineH = $this->actingAs($this->admin)->postJson("/api/lines/{$otherLine->id}/machines", ['name' => 'Conveyor'])->assertCreated();

        $this->assertSame('CONVEYOR', $onLineG->json('data.code'));
        $this->assertSame('CONVEYOR', $onLineH->json('data.code'));
    }
}
