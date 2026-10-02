<?php

namespace Tests\Feature;

use App\Enums\PartUnitStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartUnit;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Part Passport" — for a Part flagged has_passport, receiving stock
 * immediately creates one PartUnit per quantity received (QR-ready,
 * arrived_at stamped), instead of a PartUnit only coming into existence at
 * first install. See PartLifecycleService::receiveUnits() and
 * PartStockController::recordReceiving().
 */
class PartPassportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_receiving_stock_for_a_passport_part_creates_one_unit_per_quantity(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 3,
            'total_price' => 300000,
        ]);

        $response->assertOk();
        $newUnits = $response->json('new_units');
        $this->assertCount(3, $newUnits);
        $this->assertSame(['A', 'B', 'C'], collect($newUnits)->pluck('unit_code')->all());
        $this->assertSame('available', $newUnits[0]['status']);
        $this->assertNotNull($newUnits[0]['arrived_at']);
        $this->assertSame($part->id, $part->fresh()->id);
        $this->assertSame(3, $part->units()->count());
        $this->assertSame(3, $part->units()->where('status', PartUnitStatus::Available->value)->count());
    }

    public function test_receiving_stock_accepts_a_manual_arrival_date_for_backfilling_past_receipts(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 2,
            'total_price' => 200000,
            'arrived_at' => '2026-01-15',
        ]);

        $response->assertOk();
        $newUnits = $response->json('new_units');
        $this->assertSame('2026-01-15T00:00:00.000000Z', $newUnits[0]['arrived_at']);
        $this->assertSame($newUnits[0]['arrived_at'], $newUnits[1]['arrived_at']);
    }

    public function test_receiving_stock_rejects_a_future_arrival_date(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 1,
            'total_price' => 100000,
            'arrived_at' => now()->addDay()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('arrived_at');
    }

    public function test_receiving_stock_for_a_non_passport_part_creates_no_units(): void
    {
        $part = Part::factory()->create(['has_passport' => false]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 5,
            'total_price' => 500000,
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('new_units'));
        $this->assertSame(0, $part->units()->count());
    }

    public function test_store_endpoint_also_creates_units_for_a_passport_part(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);

        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/part-stocks", [
            'part_id' => $part->id,
            'quantity' => 2,
            'total_price' => 200000,
        ]);

        $response->assertCreated();
        $this->assertCount(2, $response->json('new_units'));
        $this->assertSame(2, $part->units()->count());
    }

    public function test_a_passport_received_unit_can_be_installed_and_keeps_its_arrival_date(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);
        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 1,
            'total_price' => 100000,
        ])->assertOk();

        $unit = $part->units()->sole();
        $arrivedAt = $unit->arrived_at;

        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        app(PartLifecycleService::class)->install($equipment, [
            'part_id' => $part->id,
            'part_unit_id' => $unit->id,
        ], $this->admin);

        $unit->refresh();
        $this->assertSame(PartUnitStatus::InService, $unit->status);
        $this->assertTrue($unit->arrived_at->equalTo($arrivedAt));
    }

    public function test_equipment_install_endpoint_reuses_an_existing_unit_instead_of_creating_a_new_one(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 0,
        ]);
        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 1,
            'total_price' => 100000,
        ])->assertOk();
        $unit = $part->units()->sole();

        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/part-installations", [
            'part_id' => $part->id,
            'part_unit_id' => $unit->id,
        ]);

        $response->assertCreated();
        $this->assertSame($unit->id, $response->json('data.part_unit_id'));
        $this->assertSame(1, $part->units()->count());
    }

    public function test_equipment_install_endpoint_rejects_a_unit_belonging_to_a_different_part(): void
    {
        $part = Part::factory()->create();
        $otherPart = Part::factory()->create();
        $otherUnit = PartUnit::create([
            'part_id' => $otherPart->id,
            'unit_code' => PartUnit::nextCode($otherPart->id),
            'status' => PartUnitStatus::Available,
        ]);

        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$equipment->id}/part-installations", [
            'part_id' => $part->id,
            'part_unit_id' => $otherUnit->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(PartUnitStatus::Available, $otherUnit->fresh()->status);
    }
}
