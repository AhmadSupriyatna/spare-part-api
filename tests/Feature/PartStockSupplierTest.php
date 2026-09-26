<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Inventory Workspace's standalone "Pilih Supplier" action. */
class PartStockSupplierTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private PartStock $stock;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $part = Part::factory()->create();
        $this->stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_supplier_can_be_set_without_touching_quantity(): void
    {
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
        $originalQuantity = $this->stock->quantity_on_hand;

        $response = $this->actingAs($this->admin)->putJson("/api/part-stocks/{$this->stock->id}/supplier", [
            'supplier_id' => $supplier->id,
        ]);

        $response->assertOk()->assertJsonPath('data.supplier_id', $supplier->id);
        $this->assertSame($originalQuantity, $this->stock->fresh()->quantity_on_hand);
    }

    public function test_supplier_from_a_different_branch_is_rejected(): void
    {
        $otherBranch = Branch::factory()->create();
        $this->admin->branches()->attach($otherBranch->id);
        $supplier = Supplier::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($this->admin)->putJson("/api/part-stocks/{$this->stock->id}/supplier", [
            'supplier_id' => $supplier->id,
        ])->assertUnprocessable();
    }
}
