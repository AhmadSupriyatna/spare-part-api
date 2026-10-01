<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetManualEntry;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\BudgetForecastService;
use App\Services\BudgetRealizationService;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The redesigned Budget module: global (not per-part) LifeBased
 * assumptions, a real automatic forecast for FailureBased (per-part
 * estimated failure count) and Scheduled (Task Library's own inspection
 * cadence x a per-part replacement-probability), and BudgetManualEntry for
 * both extra planned amounts and actual spend that stock_ledger never
 * sees.
 */
class BudgetModuleTest extends TestCase
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

    private function installedPart(PartReplacementStrategy $strategy, array $partAttributes = []): array
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create(array_merge(['replacement_strategy' => $strategy], $partAttributes));
        PartStock::factory()->for($part)->for($this->branch)->create(['unit_cost' => 100]);

        $user = User::factory()->create();
        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $user);

        return compact('line', 'machine', 'equipment', 'part');
    }

    public function test_life_based_forecast_uses_the_budgets_global_lifetime_percent(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'avg_weekly_operating_hours' => 70]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 1000,
        ]);
        PartStock::factory()->for($part)->for($this->branch)->create(['unit_cost' => 100]);
        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $this->admin);

        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/budgets", [
            'replacement_strategy' => 'life_based',
            'year' => 2027,
            'global_lifetime_percent' => 50,
            'global_price_increase_percent' => 0,
            'items' => [['part_id' => $part->id]],
        ]);

        $response->assertCreated();
        // hoursPerYear = 70*52 = 3640. effectiveLifetime = 1000*0.5 = 500.
        // pcs/year = 3640/500 = 7.28, x unit_cost 100 = 728.00.
        $response->assertJsonPath('data.global_lifetime_percent', '50.00');
        $this->assertSame('728.00', $response->json('data.items.0.estimated_cost'));
        $this->assertSame('728.00', $response->json('data.planned_amount'));
    }

    public function test_failure_based_forecast_uses_the_items_own_estimated_failure_count(): void
    {
        ['part' => $part] = $this->installedPart(PartReplacementStrategy::FailureBased);

        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/budgets", [
            'replacement_strategy' => 'failure_based',
            'year' => 2027,
            'global_price_increase_percent' => 10,
            'items' => [['part_id' => $part->id, 'estimated_failure_count_per_year' => 3]],
        ]);

        $response->assertCreated();
        // unit_cost 100 * 1.10 = 110, x 3 failures x 1 installation = 330.00.
        $this->assertSame('330.00', $response->json('data.items.0.estimated_cost'));
    }

    public function test_scheduled_forecast_multiplies_task_library_inspection_frequency_by_replacement_probability(): void
    {
        ['part' => $part, 'equipment' => $equipment] = $this->installedPart(PartReplacementStrategy::Scheduled);

        // 4 inspections in the trailing year that each checked this part.
        for ($i = 0; $i < 4; $i++) {
            $task = Task::factory()->for($equipment)->create(['due_date' => now()->subMonths($i)]);
            TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $part->id, 'quantity_planned' => 1]);
        }
        // Outside the 12-month window — must not count.
        $oldTask = Task::factory()->for($equipment)->create(['due_date' => now()->subMonths(14)]);
        TaskPartCheck::create(['task_id' => $oldTask->id, 'part_id' => $part->id, 'quantity_planned' => 1]);

        $response = $this->actingAs($this->admin)->postJson("/api/branches/{$this->branch->id}/budgets", [
            'replacement_strategy' => 'scheduled',
            'year' => 2027,
            'items' => [['part_id' => $part->id, 'replacement_probability_percent' => 50]],
        ]);

        $response->assertCreated();
        // 4 inspections x 50% = 2 pcs/year, x unit_cost 100 x 1 installation = 200.00.
        $this->assertSame('200.00', $response->json('data.items.0.estimated_cost'));
    }

    public function test_a_manual_planned_entry_adds_to_the_budget_total(): void
    {
        $budget = Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'failure_based',
            'year' => 2027,
            'planned_amount' => '0.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/budgets/{$budget->id}/manual-entries", [
            'type' => 'planned',
            'description' => 'Overhaul tak terduga',
            'amount' => 5_000_000,
        ]);

        $response->assertCreated();
        $this->assertSame('5000000.00', $budget->fresh()->totalAmount());
    }

    public function test_a_manual_actual_entry_adds_to_realization_total(): void
    {
        $budget = Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'failure_based',
            'year' => 2027,
            'planned_amount' => '0.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);
        BudgetManualEntry::create([
            'budget_id' => $budget->id,
            'type' => BudgetManualEntry::TYPE_ACTUAL,
            'description' => 'Servis AC via vendor luar',
            'amount' => 750000,
            'created_by' => $this->admin->id,
        ]);

        $realization = app(BudgetRealizationService::class)->calculateRealization(
            $this->branch, PartReplacementStrategy::FailureBased, 2027, $budget->fresh(['manualEntries']),
        );

        $this->assertSame('750000.00', $realization['manual']);
        $this->assertSame('750000.00', $realization['total']);
    }

    public function test_manual_entry_can_be_tagged_to_a_line_and_deleted(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $budget = Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'failure_based',
            'year' => 2027,
            'planned_amount' => '0.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/budgets/{$budget->id}/manual-entries", [
            'type' => 'actual',
            'line_id' => $line->id,
            'description' => 'Ganti part darurat',
            'amount' => 100000,
        ]);
        $response->assertCreated();
        $response->assertJsonPath('data.line_name', $line->name);

        $entryId = $response->json('data.id');
        $this->actingAs($this->admin)->deleteJson("/api/budget-manual-entries/{$entryId}")->assertNoContent();
        $this->assertSame(0, BudgetManualEntry::count());
    }

    public function test_by_machine_breakdown_separates_line_machines_from_outside_line_machines(): void
    {
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $inlineMachine = Machine::factory()->create(['line_id' => $line->id]);
        $inlineEquipment = Equipment::factory()->create(['machine_id' => $inlineMachine->id]);
        $outsideMachine = Machine::factory()->create(['line_id' => null, 'branch_id' => $this->branch->id]);
        $outsideEquipment = Equipment::factory()->create(['machine_id' => $outsideMachine->id]);

        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::FailureBased,
        ]);
        PartStock::factory()->for($part)->for($this->branch)->create(['unit_cost' => 50]);
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($inlineEquipment, ['part_id' => $part->id], $this->admin);
        $lifecycle->install($outsideEquipment, ['part_id' => $part->id], $this->admin);

        $budget = Budget::create([
            'branch_id' => $this->branch->id,
            'replacement_strategy' => 'failure_based',
            'global_price_increase_percent' => 0,
            'year' => 2027,
            'planned_amount' => '0.00',
            'corrective_amount' => '0.00',
            'created_by' => $this->admin->id,
        ]);
        $item = $budget->items()->create([
            'part_id' => $part->id,
            'estimated_failure_count_per_year' => 2,
            'unit_cost' => 50,
            'estimated_cost' => 200,
        ]);

        $breakdown = app(BudgetForecastService::class)->machineBreakdown($this->branch, $item->fresh(['budget', 'part']));

        $labels = collect($breakdown)->pluck('location_label')->sort()->values()->all();
        $expected = collect(['Luar Line', $line->name])->sort()->values()->all();
        $this->assertSame($expected, $labels);
    }

    public function test_engineer_cannot_access_budgets(): void
    {
        $engineer = User::factory()->create();
        $engineer->assignRole(UserRole::Engineer->value);
        $engineer->branches()->attach($this->branch->id);

        $this->actingAs($engineer)->getJson("/api/branches/{$this->branch->id}/budgets")->assertForbidden();
    }
}
