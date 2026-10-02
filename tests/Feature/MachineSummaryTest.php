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
}
