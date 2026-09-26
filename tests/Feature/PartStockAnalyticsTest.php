<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\ReplacementRequestStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\PartUnit;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Part Detail's info-card dashboard — bundled analytics for one PartStock. */
class PartStockAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private Machine $machine;

    private Equipment $equipment;

    private Part $part;

    private PartStock $stock;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 500]);
        $this->machine = Machine::factory()->create(['line_id' => $this->line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $this->machine->id]);
        $this->part = Part::factory()->create(['replacement_strategy' => PartReplacementStrategy::FailureBased]);
        $this->stock = PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_monthly_usage_sums_issue_ledger_entries_for_the_current_month(): void
    {
        StockLedger::create([
            'part_stock_id' => $this->stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -3,
            'balance_after' => 7,
            'unit_cost' => '1000.00',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/analytics");

        $response->assertOk();
        $monthlyUsage = $response->json('data.monthly_usage');
        $this->assertCount(12, $monthlyUsage);
        $this->assertSame(3, $monthlyUsage[11]['quantity']);
    }

    public function test_heatmap_counts_active_installations_by_line_and_machine(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->admin);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/analytics");

        $response->assertOk();
        $heatmap = $response->json('data.line_machine_heatmap');
        $this->assertCount(1, $heatmap);
        $this->assertSame($this->line->name, $heatmap[0]['line_name']);
        $this->assertSame($this->machine->name, $heatmap[0]['machine_name']);
        $this->assertSame(1, $heatmap[0]['count']);
    }

    public function test_failure_breakdown_count_only_counts_those_two_event_types(): void
    {
        PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'event_type' => 'failure',
            'status' => ReplacementRequestStatus::Pending,
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);
        PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'event_type' => 'maintenance',
            'status' => ReplacementRequestStatus::Pending,
            'reason' => 'Terjadwal',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/analytics");

        $response->assertOk()->assertJsonPath('data.failure_breakdown_count', 1);
    }

    public function test_average_lifetime_hours_averages_runtime_across_installations(): void
    {
        PartInstallation::create([
            'equipment_id' => $this->equipment->id,
            'part_id' => $this->part->id,
            'part_unit_id' => PartUnit::create(['part_id' => $this->part->id, 'unit_code' => 'A', 'status' => 'in_service'])->id,
            'installed_at' => now()->subDays(10),
            'installed_at_runtime_hours' => 300,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/analytics");

        $response->assertOk()->assertJsonPath('data.average_lifetime_hours', 200);
    }

    public function test_average_lifetime_hours_is_null_with_no_installations(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/part-stocks/{$this->stock->id}/analytics");

        $response->assertOk()->assertJsonPath('data.average_lifetime_hours', null);
    }
}
