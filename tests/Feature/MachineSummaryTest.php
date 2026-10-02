<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Machine Detail's authenticated dashboard data — the same aggregates as
 * the public QR-scan monitoring page, plus cost fields (installed_part_value,
 * per-month/per-part cost) that only ever appear here, never on the public
 * endpoint. See MachineSummaryService's `$includeCost` flag.
 */
class MachineSummaryTest extends TestCase
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

    public function test_summary_endpoint_requires_authentication(): void
    {
        $machine = Machine::factory()->create();

        $this->getJson("/api/machines/{$machine->id}/summary")->assertUnauthorized();
    }

    public function test_summary_includes_installed_part_value_based_on_current_branch_unit_cost(): void
    {
        $line = ProductionLine::factory()->create();
        $branch = $line->branch;
        $this->admin->branches()->attach($branch->id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $branch->id, 'unit_cost' => '120000.00']);
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/summary");

        $response->assertOk();
        $this->assertSame('120000.00', $response->json('data.installed_part_value'));
    }

    public function test_summary_includes_cost_in_consumption_trend_and_top_parts(): void
    {
        $line = ProductionLine::factory()->create();
        $branch = $line->branch;
        $this->admin->branches()->attach($branch->id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create(['name' => 'Seal']);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -2,
            'balance_after' => 8,
            'unit_cost' => '40000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/summary");

        $response->assertOk();
        $currentMonth = now()->format('Y-m');
        $trend = collect($response->json('data.monthly_consumption_trend'))->keyBy('month');
        $this->assertSame('80000.00', $trend[$currentMonth]['cost']);

        $topParts = collect($response->json('data.top_parts_consumed'));
        $sealRow = $topParts->firstWhere('part_name', 'Seal');
        $this->assertSame('80000.00', $sealRow['cost']);
    }

    public function test_summary_includes_cost_by_equipment_for_the_current_calendar_year_only(): void
    {
        $line = ProductionLine::factory()->create();
        $branch = $line->branch;
        $this->admin->branches()->attach($branch->id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'name' => 'Gearbox A']);
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 9,
            'unit_cost' => '30000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);
        // Last year — must not count toward "this year".
        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 8,
            'unit_cost' => '999999.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/summary");

        $response->assertOk();
        $row = collect($response->json('data.cost_by_equipment_this_year'))->firstWhere('equipment_name', 'Gearbox A');
        $this->assertSame('30000.00', $row['cost']);
    }

    public function test_summary_includes_historical_part_lifetime_from_completed_installations_only(): void
    {
        $line = ProductionLine::factory()->create();
        $this->admin->branches()->attach($line->branch_id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create(['name' => 'Bearing']);

        PartInstallation::create([
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'installed_at' => now()->subMonths(6),
            'installed_at_runtime_hours' => 1000,
            'removed_at' => now()->subMonths(1),
            'removed_at_runtime_hours' => 2000,
        ]);
        PartInstallation::create([
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'installed_at' => now()->subMonths(1),
            'installed_at_runtime_hours' => 2000,
            // Still active — must not count, no final hours yet.
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/summary");

        $response->assertOk();
        $row = collect($response->json('data.historical_part_lifetime'))->firstWhere('part_name', 'Bearing');
        $this->assertSame(1000, $row['average_runtime_hours']);
        $this->assertSame(1, $row['sample_count']);
    }
}
