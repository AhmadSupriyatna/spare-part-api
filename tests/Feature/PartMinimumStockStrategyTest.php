<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Unit;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A Part's effective minimum_stock (displayed only, never drives the
 * Kritis/Peringatan badge — see PartStockStatusBadgeTest) is computed from
 * its minimum_stock_strategy: "standard" reads the owning Plant/Branch's
 * own default_minimum_stock, "installed" reads that part's own
 * active_installation_count in that branch instead.
 */
class PartMinimumStockStrategyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private User $admin;

    private User $installer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        Unit::create(['name' => 'pcs']);

        $this->branch = Branch::factory()->create(['default_minimum_stock' => 20]);
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
        $this->installer = User::factory()->create();
    }

    public function test_standard_strategy_uses_the_branchs_default_minimum_stock(): void
    {
        $part = Part::factory()->create(['minimum_stock_strategy' => 'standard']);
        PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);

        $data = $this->actingAs($this->admin)
            ->getJson("/api/branches/{$this->branch->id}/part-stocks")
            ->json('data.0');

        $this->assertSame('standard', $data['minimum_stock_strategy']);
        $this->assertSame(20, $data['minimum_stock']);
    }

    public function test_installed_strategy_uses_the_active_installation_count(): void
    {
        $part = Part::factory()->create(['minimum_stock_strategy' => 'installed']);
        PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);

        $lifecycle = app(PartLifecycleService::class);
        for ($i = 0; $i < 3; $i++) {
            $equipment = Equipment::factory()->create(['machine_id' => $this->equipment->machine_id]);
            $lifecycle->install($equipment, ['part_id' => $part->id], $this->installer);
        }

        $data = $this->actingAs($this->admin)
            ->getJson("/api/branches/{$this->branch->id}/part-stocks")
            ->json('data.0');

        $this->assertSame('installed', $data['minimum_stock_strategy']);
        $this->assertSame(3, $data['minimum_stock']);
    }

    public function test_part_registration_defaults_to_standard_strategy_when_omitted(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/parts', [
            'item_master_no' => 'SP-9001',
            'name' => 'Test Part',
            'unit' => 'pcs',
            'replacement_strategy' => 'failure_based',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.minimum_stock_strategy', 'standard');
    }

    public function test_part_registration_accepts_an_explicit_installed_strategy(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/parts', [
            'item_master_no' => 'SP-9002',
            'name' => 'Test Part 2',
            'unit' => 'pcs',
            'replacement_strategy' => 'failure_based',
            'minimum_stock_strategy' => 'installed',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.minimum_stock_strategy', 'installed');
    }

    public function test_admin_can_set_the_plants_default_minimum_stock(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/branches/{$this->branch->id}", [
            'default_minimum_stock' => 15,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.default_minimum_stock', 15);
    }
}
