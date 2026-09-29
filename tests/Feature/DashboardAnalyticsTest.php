<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard chart data: failure trend, cost heatmap, budget projection — see
 * DashboardAnalyticsController's own docblock. Covers all three branch-
 * membership shapes an Equipment can now have (production, under an
 * outside-line Machine, fully standalone), since these aggregations use
 * their own equipment->branch resolution rather than PartStock's.
 */
class DashboardAnalyticsTest extends TestCase
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

    private function fetchAnalytics(): TestResponse
    {
        return $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/dashboard-analytics");
    }

    public function test_failure_trend_counts_events_for_production_equipment(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        PartReplacementRequest::create([
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'event_type' => 'failure',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.failure_trend'))->keyBy('month');

        $this->assertSame(1, $trend[now()->format('Y-m')]['count']);
    }

    public function test_failure_trend_counts_events_under_an_outside_line_machine(): void
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        PartReplacementRequest::create([
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'event_type' => 'breakdown',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.failure_trend'))->keyBy('month');

        $this->assertSame(1, $trend[now()->format('Y-m')]['count']);
    }

    public function test_failure_trend_ignores_a_different_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $otherBranch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        PartReplacementRequest::create([
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'event_type' => 'failure',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.failure_trend'))->keyBy('month');

        $this->assertSame(0, $trend[now()->format('Y-m')]['count']);
    }

    public function test_cost_heatmap_groups_by_line_and_machine_and_sums_cost(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -2,
            'balance_after' => 8,
            'unit_cost' => '50000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);
        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 7,
            'unit_cost' => '50000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $heatmap = collect($this->fetchAnalytics()->json('data.cost_heatmap'));
        $cell = $heatmap->firstWhere('line_name', 'Line A');

        $this->assertNotNull($cell);
        $this->assertSame('Mesin Press', $cell['machine_name']);
        $this->assertSame('150000.00', $cell['cost']);
    }

    public function test_cost_heatmap_buckets_a_standalone_asset_under_luar_line_using_its_own_name(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'name' => 'Panel Listrik']);
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $replacementRequest = PartReplacementRequest::create([
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'event_type' => 'breakdown',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 9,
            'unit_cost' => '25000.00',
            'reference_type' => PartReplacementRequest::class,
            'reference_id' => $replacementRequest->id,
            'occurred_at' => now(),
        ]);

        $heatmap = collect($this->fetchAnalytics()->json('data.cost_heatmap'));
        $cell = $heatmap->firstWhere('machine_name', 'Panel Listrik');

        $this->assertNotNull($cell);
        $this->assertSame('Luar Line', $cell['line_name']);
        $this->assertSame('25000.00', $cell['cost']);
    }

    public function test_budget_projection_spreads_the_years_planned_total_evenly_across_12_months(): void
    {
        Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'life_based',
            'year' => (int) now()->format('Y'),
            'planned_amount' => '1200000.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $projection = $this->fetchAnalytics()->json('data.budget_projection');

        $this->assertSame((int) now()->format('Y'), $projection['year']);
        $this->assertSame('1200000.00', $projection['planned_total']);
        $this->assertSame('100000.00', $projection['rows'][0]['planned_cumulative']);
        $this->assertSame('1200000.00', $projection['rows'][11]['planned_cumulative']);
    }

    public function test_budget_projection_actual_cumulative_is_null_for_a_month_that_has_not_happened_yet(): void
    {
        $projection = $this->fetchAnalytics()->json('data.budget_projection');

        $futureMonth = collect($projection['rows'])->last();
        if ($futureMonth['month'] === now()->format('Y-m')) {
            $this->markTestSkipped('Current month is December — every month has "happened" this year.');
        }

        $this->assertNull($futureMonth['actual_cumulative']);
    }
}
