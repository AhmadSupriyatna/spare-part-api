<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Editing/deleting a Stock In (receiving) entry — restricted to the single
 * most recent receiving entry of a part_stock (see StockLedgerController's
 * own docblock for why). Everything else in stock_ledger stays append-only.
 */
class StockLedgerEditTest extends TestCase
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

    public function test_can_edit_the_latest_receiving_entry(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 100000,
        ])->assertOk();

        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$entry->id}/receiving", [
            'quantity' => 20,
            'total_price' => 200000,
        ]);

        $response->assertOk();
        $this->assertSame(20, $stock->fresh()->quantity_on_hand);
        $this->assertSame('10000.00', $stock->fresh()->unit_cost);
        $entry->refresh();
        $this->assertSame(20, $entry->quantity_change);
        $this->assertSame(20, $entry->balance_after);
    }

    public function test_can_delete_the_latest_receiving_entry(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 100000,
        ])->assertOk();
        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->deleteJson("/api/stock-ledger/{$entry->id}/receiving");

        $response->assertOk();
        $this->assertSame(0, $stock->fresh()->quantity_on_hand);
        $this->assertSame(0, StockLedger::where('part_stock_id', $stock->id)->count());
    }

    public function test_editing_restores_the_correct_pre_entry_state_with_prior_history(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        // First receiving: 10 units @ 10,000/unit.
        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 100000,
        ])->assertOk();
        // Second (latest) receiving: 10 units @ 20,000/unit -> weighted avg (10*10000 + 200000)/20 = 15000.
        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 200000,
        ])->assertOk();
        $this->assertSame('15000.00', $stock->fresh()->unit_cost);

        $latest = StockLedger::where('part_stock_id', $stock->id)->orderByDesc('id')->first();

        // Edit the latest to 5 units @ 20,000/unit -> weighted avg (10*10000 + 100000)/15 = 13333.33.
        $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$latest->id}/receiving", [
            'quantity' => 5,
            'total_price' => 100000,
        ])->assertOk();

        $this->assertSame(15, $stock->fresh()->quantity_on_hand);
        $this->assertSame('13333.33', $stock->fresh()->unit_cost);
    }

    public function test_cannot_edit_a_receiving_entry_that_is_not_the_latest(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 100000,
        ])->assertOk();
        $firstEntry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 5,
            'total_price' => 50000,
        ])->assertOk();

        $response = $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$firstEntry->id}/receiving", [
            'quantity' => 99,
            'total_price' => 990000,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_edit_a_non_receiving_entry(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10, 'unit_cost' => 1000]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/adjust", [
            'quantity_change' => -2,
            'reason' => 'Opname',
        ])->assertOk();
        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$entry->id}/receiving", [
            'quantity' => 1,
            'total_price' => 1000,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_change_quantity_on_a_receiving_entry_for_a_passport_part(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 3,
            'total_price' => 30000,
        ])->assertOk();
        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$entry->id}/receiving", [
            'quantity' => 5,
            'total_price' => 50000,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_edit_price_only_on_a_receiving_entry_for_a_passport_part(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 3,
            'total_price' => 30000,
        ])->assertOk();
        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->putJson("/api/stock-ledger/{$entry->id}/receiving", [
            'quantity' => 3,
            'total_price' => 60000,
        ]);

        $response->assertOk();
        $this->assertSame('20000.00', $stock->fresh()->unit_cost);
    }

    public function test_cannot_delete_a_receiving_entry_for_a_passport_part(): void
    {
        $part = Part::factory()->create(['has_passport' => true]);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 3,
            'total_price' => 30000,
        ])->assertOk();
        $entry = StockLedger::where('part_stock_id', $stock->id)->sole();

        $response = $this->actingAs($this->admin)->deleteJson("/api/stock-ledger/{$entry->id}/receiving");

        $response->assertStatus(422);
    }

    public function test_branch_ledger_marks_only_the_true_latest_receiving_entry_as_editable(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 0, 'unit_cost' => 0]);

        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 10,
            'total_price' => 100000,
        ])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/part-stocks/{$stock->id}/receive", [
            'quantity' => 5,
            'total_price' => 50000,
        ])->assertOk();

        $response = $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/stock-ledger");

        $response->assertOk();
        $rows = collect($response->json('data'))->sortBy('id')->values();
        $this->assertFalse($rows[0]['is_editable']);
        $this->assertTrue($rows[1]['is_editable']);
    }
}
