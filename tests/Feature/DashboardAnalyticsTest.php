<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Equipment;
use App\Models\Fp3Request;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\PartReplacementRequestItem;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_engineer_cannot_view_dashboard_analytics(): void
    {
        $engineer = User::factory()->create();
        $engineer->assignRole(UserRole::Engineer->value);
        $engineer->branches()->attach($this->branch->id);

        $this->actingAs($engineer)
            ->getJson("/api/branches/{$this->branch->id}/dashboard-analytics")
            ->assertForbidden();
    }

    private function fetchAnalytics(?int $year = null): TestResponse
    {
        $query = $year !== null ? '?year='.$year : '';

        return $this->actingAs($this->admin)->getJson("/api/branches/{$this->branch->id}/dashboard-analytics{$query}");
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

        $this->assertSame(1, $trend[now()->format('Y-m')]['failure_count']);
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

        $this->assertSame(1, $trend[now()->format('Y-m')]['failure_count']);
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

        $this->assertSame(0, $trend[now()->format('Y-m')]['failure_count']);
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

    public function test_failure_trend_replacement_count_counts_new_part_installations(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();

        PartInstallation::create([
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'installed_at' => now(),
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.failure_trend'))->keyBy('month');

        $this->assertSame(1, $trend[now()->format('Y-m')]['replacement_count']);
    }

    public function test_failure_trend_replacement_cost_sums_spend_by_month(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create();

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -2,
            'balance_after' => 8,
            'unit_cost' => '75000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.failure_trend'))->keyBy('month');

        $this->assertSame('150000.00', $trend[now()->format('Y-m')]['replacement_cost']);
    }

    public function test_failure_radial_groups_failure_breakdown_reports_by_line_and_machine(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();

        foreach (['failure', 'breakdown'] as $eventType) {
            PartReplacementRequest::create([
                'part_id' => $part->id,
                'equipment_id' => $equipment->id,
                'event_type' => $eventType,
                'status' => 'pending',
                'reason' => 'Rusak',
                'requested_by_name' => 'Teknisi',
                'quantity_used' => 1,
            ]);
        }

        $radial = collect($this->fetchAnalytics()->json('data.failure_radial'));
        $cell = $radial->firstWhere('line_name', 'Line A');

        $this->assertNotNull($cell);
        $this->assertSame('Mesin Press', $cell['machine_name']);
        $this->assertSame(2, $cell['count']);
    }

    public function test_maintenance_performance_splits_completed_tasks_by_on_time_vs_late(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        Task::factory()->create([
            'equipment_id' => $equipment->id,
            'status' => TaskStatus::Completed,
            'due_date' => now()->subDays(2),
            'completed_at' => now()->subDays(3),
        ]);
        Task::factory()->create([
            'equipment_id' => $equipment->id,
            'status' => TaskStatus::Completed,
            'due_date' => now()->subDays(5),
            'completed_at' => now()->subDays(1),
        ]);

        $performance = $this->fetchAnalytics()->json('data.maintenance_performance');

        $this->assertSame(1, $performance['on_time_count']);
        $this->assertSame(1, $performance['late_count']);
    }

    public function test_at_risk_parts_surfaces_an_installation_past_the_threshold(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 0, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 100,
        ]);

        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $this->admin);
        $line->update(['runtime_hours' => 85]); // 85/100 = 85% used, above the 80% threshold

        $atRisk = collect($this->fetchAnalytics()->json('data.at_risk_parts'));
        $entry = $atRisk->firstWhere('part_name', $part->name);

        $this->assertNotNull($entry);
        $this->assertSame('Line A', $entry['line_name']);
        $this->assertSame(85, $entry['percent_used']);
    }

    public function test_installation_map_counts_active_installations_by_line_and_machine(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $partA = Part::factory()->create();
        $partB = Part::factory()->create();

        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $partA->id, 'installed_at' => now()]);
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $partB->id, 'installed_at' => now()]);
        // Removed — must not be counted.
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $partA->id, 'installed_at' => now(), 'removed_at' => now()]);

        $map = collect($this->fetchAnalytics()->json('data.installation_map'));
        $cell = $map->firstWhere('line_name', 'Line A');

        $this->assertNotNull($cell);
        $this->assertSame('Mesin Press', $cell['machine_name']);
        $this->assertSame(2, $cell['count']);
    }

    public function test_cost_by_part_sums_spend_grouped_by_part(): void
    {
        $part = Part::factory()->create(['name' => 'Gear Reducer 1:20']);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create();

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -3,
            'balance_after' => 7,
            'unit_cost' => '20000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $byPart = collect($this->fetchAnalytics()->json('data.cost_by_part'));
        $row = $byPart->firstWhere('part_name', 'Gear Reducer 1:20');

        $this->assertNotNull($row);
        $this->assertSame('60000.00', $row['cost']);
    }

    public function test_stock_value_sums_quantity_times_unit_cost(): void
    {
        $partA = Part::factory()->create();
        $partB = Part::factory()->create();
        PartStock::factory()->create(['part_id' => $partA->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10, 'unit_cost' => '5000.00']);
        PartStock::factory()->create(['part_id' => $partB->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 3, 'unit_cost' => '2000.00']);

        $value = $this->fetchAnalytics()->json('data.stock_value');

        $this->assertSame('56000.00', $value); // 10*5000 + 3*2000
    }

    public function test_pm_vs_failure_cost_splits_by_reference_type(): void
    {
        $equipment = Equipment::factory()->create();
        $library = TaskLibrary::create([
            'equipment_id' => $equipment->id,
            'code' => 'TL-1',
            'title' => 'Cek Rutin',
            'is_active' => true,
        ]);
        $pmTask = Task::factory()->create(['equipment_id' => $equipment->id, 'task_library_id' => $library->id]);
        $breakdownTask = Task::factory()->create(['equipment_id' => $equipment->id]);

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
        $fp3Request = Fp3Request::create([
            'branch_id' => $this->branch->id,
            'code' => '1/FP3/01/2026',
            'requester_name' => 'Operator',
            'department' => 'Produksi',
            'description' => 'Rusak',
            'request_photo_path' => 'fp3-requests/dummy.jpg',
            'status' => 'pending',
        ]);

        $makeLedger = function (string $referenceType, int $referenceId, string $unitCost) use ($stock) {
            StockLedger::create([
                'part_stock_id' => $stock->id,
                'type' => StockLedger::TYPE_ISSUE,
                'quantity_change' => -1,
                'balance_after' => 9,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
            ]);
        };

        $makeLedger(Task::class, $pmTask->id, '10000.00');
        $makeLedger(Task::class, $breakdownTask->id, '20000.00');
        $makeLedger(PartReplacementRequest::class, $replacementRequest->id, '5000.00');
        $makeLedger(Fp3Request::class, $fp3Request->id, '3000.00');

        $split = $this->fetchAnalytics()->json('data.pm_vs_failure_cost');

        $this->assertSame('10000.00', $split['pm_cost']);
        $this->assertSame('28000.00', $split['failure_cost']); // 20000 + 5000 + 3000
    }

    public function test_equipment_coverage_counts_equipment_without_an_active_task_library(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $covered = Equipment::factory()->create(['machine_id' => $machine->id]);
        Equipment::factory()->create(['machine_id' => $machine->id]); // uncovered

        TaskLibrary::create([
            'equipment_id' => $covered->id,
            'code' => 'TL-1',
            'title' => 'Cek Rutin',
            'is_active' => true,
        ]);

        $coverage = $this->fetchAnalytics()->json('data.equipment_coverage');

        $this->assertSame(2, $coverage['total_equipment']);
        $this->assertSame(1, $coverage['without_library']);
    }

    public function test_technician_workload_counts_open_tasks_per_assignee(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $engineer = User::factory()->create(['name' => 'Budi Teknisi']);

        Task::factory()->create(['equipment_id' => $equipment->id, 'assigned_to' => $engineer->id, 'status' => TaskStatus::Pending]);
        Task::factory()->create(['equipment_id' => $equipment->id, 'assigned_to' => $engineer->id, 'status' => TaskStatus::InProgress]);
        // Completed — must not count toward open workload.
        Task::factory()->create(['equipment_id' => $equipment->id, 'assigned_to' => $engineer->id, 'status' => TaskStatus::Completed]);

        $workload = collect($this->fetchAnalytics()->json('data.technician_workload'));
        $row = $workload->firstWhere('name', 'Budi Teknisi');

        $this->assertNotNull($row);
        $this->assertSame(2, $row['open_count']);
    }

    public function test_part_movement_lists_fast_movers_and_zero_issue_slow_movers(): void
    {
        $fastPart = Part::factory()->create(['name' => 'Bearing 6204']);
        $slowPart = Part::factory()->create(['name' => 'Seal Oring']);
        $fastStock = PartStock::factory()->create(['part_id' => $fastPart->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 5]);
        PartStock::factory()->create(['part_id' => $slowPart->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 20]);
        $task = Task::factory()->create();

        StockLedger::create([
            'part_stock_id' => $fastStock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -4,
            'balance_after' => 5,
            'unit_cost' => '1000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $movement = $this->fetchAnalytics()->json('data.part_movement');
        $fastRow = collect($movement['fast_moving'])->firstWhere('part_name', 'Bearing 6204');
        $slowRow = collect($movement['slow_moving'])->firstWhere('part_name', 'Seal Oring');

        $this->assertNotNull($fastRow);
        $this->assertSame(4, $fastRow['qty_issued']);
        $this->assertNotNull($slowRow);
        $this->assertSame(20, $slowRow['quantity_on_hand']);
    }

    public function test_stock_movement_trend_buckets_receiving_and_issue_by_month(): void
    {
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_RECEIVING,
            'quantity_change' => 15,
            'balance_after' => 15,
            'occurred_at' => now(),
        ]);
        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -6,
            'balance_after' => 9,
            'occurred_at' => now(),
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.stock_movement_trend'))->keyBy('month');
        $thisMonth = $trend[now()->format('Y-m')];

        $this->assertSame(15, $thisMonth['in_qty']);
        $this->assertSame(6, $thisMonth['out_qty']);
    }

    public function test_installation_sunburst_counts_active_installations_by_full_hierarchy(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'name' => 'Pompa 1']);
        $part = Part::factory()->create(['name' => 'Gear Reducer 1:20']);

        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);
        // Removed — must not be counted.
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now(), 'removed_at' => now()]);

        $rows = collect($this->fetchAnalytics()->json('data.installation_sunburst'));
        $row = $rows->firstWhere('part_name', 'Gear Reducer 1:20');

        $this->assertNotNull($row);
        $this->assertSame('Line A', $row['line_name']);
        $this->assertSame('Mesin Press', $row['machine_name']);
        $this->assertSame('Pompa 1', $row['equipment_name']);
        $this->assertSame(2, $row['count']);
    }

    public function test_installation_sunburst_buckets_a_standalone_asset_under_luar_line(): void
    {
        $equipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id, 'name' => 'Panel Listrik']);
        $part = Part::factory()->create();

        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);

        $rows = collect($this->fetchAnalytics()->json('data.installation_sunburst'));
        $row = $rows->firstWhere('equipment_name', 'Panel Listrik');

        $this->assertNotNull($row);
        $this->assertSame('Luar Line', $row['line_name']);
        $this->assertSame('Panel Listrik', $row['machine_name']);
    }

    public function test_cost_sunburst_groups_spend_by_full_hierarchy(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id, 'name' => 'Pompa 1']);
        $part = Part::factory()->create(['name' => 'Gear Reducer 1:20']);
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

        $rows = collect($this->fetchAnalytics()->json('data.cost_sunburst'));
        $row = $rows->firstWhere('part_name', 'Gear Reducer 1:20');

        $this->assertNotNull($row);
        $this->assertSame('Line A', $row['line_name']);
        $this->assertSame('Mesin Press', $row['machine_name']);
        $this->assertSame('Pompa 1', $row['equipment_name']);
        $this->assertSame('100000.00', $row['cost']);
    }

    public function test_cost_sunburst_excludes_spend_from_a_previous_calendar_year(): void
    {
        $part = Part::factory()->create(['name' => 'Gear Reducer 1:20']);
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create();

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 9,
            'unit_cost' => '50000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now()->subYear(),
        ]);

        $rows = collect($this->fetchAnalytics()->json('data.cost_sunburst'));

        $this->assertNull($rows->firstWhere('part_name', 'Gear Reducer 1:20'));
    }

    public function test_scheduled_vs_failure_trend_counts_a_pm_driven_install_as_scheduled(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);

        $installation = PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);

        TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $part->id,
            'quantity_planned' => 1,
            'is_replaced' => true,
            'quantity_used' => 1,
            'part_installation_id' => $installation->id,
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.scheduled_vs_failure_trend'))->keyBy('month');
        $thisMonth = $trend[now()->format('Y-m')];

        $this->assertSame(1, $thisMonth['scheduled_count']);
        $this->assertSame(0, $thisMonth['failure_count']);
    }

    public function test_scheduled_vs_failure_trend_counts_a_breakdown_driven_install_as_failure(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();

        $installation = PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);

        $replacementRequest = PartReplacementRequest::create([
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'event_type' => 'breakdown',
            'status' => 'approved',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);
        PartReplacementRequestItem::create([
            'replacement_request_id' => $replacementRequest->id,
            'new_installation_id' => $installation->id,
        ]);

        $trend = collect($this->fetchAnalytics()->json('data.scheduled_vs_failure_trend'))->keyBy('month');
        $thisMonth = $trend[now()->format('Y-m')];

        $this->assertSame(0, $thisMonth['scheduled_count']);
        $this->assertSame(1, $thisMonth['failure_count']);
    }

    public function test_scheduled_vs_failure_trend_does_not_count_a_manual_install_in_either_series(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();

        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id, 'installed_at' => now()]);

        $trend = collect($this->fetchAnalytics()->json('data.scheduled_vs_failure_trend'))->keyBy('month');
        $thisMonth = $trend[now()->format('Y-m')];

        $this->assertSame(0, $thisMonth['scheduled_count']);
        $this->assertSame(0, $thisMonth['failure_count']);
    }

    public function test_response_includes_the_resolved_year_defaulting_to_the_current_year(): void
    {
        $this->assertSame((int) now()->format('Y'), $this->fetchAnalytics()->json('data.year'));
    }

    public function test_year_query_param_scopes_cost_heatmap_to_the_requested_year_not_the_current_one(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);
        $lastYear = (int) now()->format('Y') - 1;

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 9,
            'unit_cost' => '40000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => Carbon::create($lastYear, 6, 15),
        ]);

        $this->assertNull(collect($this->fetchAnalytics()->json('data.cost_heatmap'))->firstWhere('line_name', 'Line A'));

        $cell = collect($this->fetchAnalytics($lastYear)->json('data.cost_heatmap'))->firstWhere('line_name', 'Line A');
        $this->assertNotNull($cell);
        $this->assertSame('40000.00', $cell['cost']);
    }

    public function test_year_query_param_changes_the_budget_projection_year(): void
    {
        $lastYear = (int) now()->format('Y') - 1;
        Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'life_based',
            'year' => $lastYear,
            'planned_amount' => '600000.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $projection = $this->fetchAnalytics($lastYear)->json('data.budget_projection');

        $this->assertSame($lastYear, $projection['year']);
        $this->assertSame('600000.00', $projection['planned_total']);
    }

    public function test_available_years_always_includes_the_current_year_with_no_data_at_all(): void
    {
        $years = $this->fetchAnalytics()->json('data.available_years');

        $this->assertSame([(int) now()->format('Y')], $years);
    }

    public function test_available_years_includes_a_year_that_only_has_a_budget(): void
    {
        $lastYear = (int) now()->format('Y') - 1;
        Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'life_based',
            'year' => $lastYear,
            'planned_amount' => '100000.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $years = $this->fetchAnalytics()->json('data.available_years');

        $this->assertContains($lastYear, $years);
    }

    public function test_available_years_includes_a_year_that_only_has_a_stock_ledger_entry(): void
    {
        $twoYearsAgo = (int) now()->format('Y') - 2;
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create();

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -1,
            'balance_after' => 9,
            'unit_cost' => '10000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => Carbon::create($twoYearsAgo, 3, 1),
        ]);

        $years = $this->fetchAnalytics()->json('data.available_years');

        $this->assertContains($twoYearsAgo, $years);
    }

    public function test_available_years_excludes_a_year_from_a_different_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $lastYear = (int) now()->format('Y') - 1;
        Budget::create([
            'branch_id' => $otherBranch->id,
            'replacement_strategy' => 'life_based',
            'year' => $lastYear,
            'planned_amount' => '100000.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $years = $this->fetchAnalytics()->json('data.available_years');

        $this->assertNotContains($lastYear, $years);
    }

    public function test_monthly_cost_by_line_groups_spend_by_month_and_line(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Line A']);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);
        $task = Task::factory()->create(['equipment_id' => $equipment->id]);

        StockLedger::create([
            'part_stock_id' => $stock->id,
            'type' => StockLedger::TYPE_ISSUE,
            'quantity_change' => -2,
            'balance_after' => 8,
            'unit_cost' => '30000.00',
            'reference_type' => Task::class,
            'reference_id' => $task->id,
            'occurred_at' => now(),
        ]);

        $rows = collect($this->fetchAnalytics()->json('data.monthly_cost_by_line'));
        $row = $rows->firstWhere('line_name', 'Line A');

        $this->assertNotNull($row);
        $this->assertSame(now()->format('Y-m'), $row['month']);
        $this->assertSame('60000.00', $row['cost']);
    }

    public function test_monthly_cost_by_pm_failure_splits_by_month_and_reference_type(): void
    {
        $equipment = Equipment::factory()->create();
        $library = TaskLibrary::create([
            'equipment_id' => $equipment->id,
            'code' => 'TL-1',
            'title' => 'Cek Rutin',
            'is_active' => true,
        ]);
        $pmTask = Task::factory()->create(['equipment_id' => $equipment->id, 'task_library_id' => $library->id]);
        $breakdownTask = Task::factory()->create(['equipment_id' => $equipment->id]);

        $part = Part::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id]);

        $makeLedger = function (string $referenceType, int $referenceId, string $unitCost) use ($stock) {
            StockLedger::create([
                'part_stock_id' => $stock->id,
                'type' => StockLedger::TYPE_ISSUE,
                'quantity_change' => -1,
                'balance_after' => 9,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'occurred_at' => now(),
            ]);
        };

        $makeLedger(Task::class, $pmTask->id, '15000.00');
        $makeLedger(Task::class, $breakdownTask->id, '8000.00');

        $rows = collect($this->fetchAnalytics()->json('data.monthly_cost_by_pm_failure'))->keyBy('month');
        $thisMonth = $rows[now()->format('Y-m')];

        $this->assertSame('15000.00', $thisMonth['pm_cost']);
        $this->assertSame('8000.00', $thisMonth['failure_cost']);
    }
}
