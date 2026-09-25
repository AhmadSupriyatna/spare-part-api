<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\PartLifetimeService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B Step 5 — Life Based Task scheduling. There is no PM Planning/
 * MaintenanceWorkOrder layer any more (removed per explicit request: every
 * Task, whether from a Task Library recipe or a Life Based trigger, is
 * scheduled and executed directly — no manual "group into a WO" step, the
 * PM Calendar's own drag-and-drop reschedule is the correction mechanism).
 * These tests confirm a Life Based Task is a completely ordinary Task —
 * Pending, no WO of any kind, executes and replaces its part normally —
 * plus the duplicate-protection and threshold behavior nothing previously
 * exercised at all.
 */
class LifeBasedPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private Equipment $equipment;

    private Part $part;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 0]);
        $machine = Machine::factory()->create(['line_id' => $this->line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $this->part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 100,
        ]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::Engineer->value);
        $this->user->branches()->attach($this->branch->id);
    }

    /**
     * Installs the LifeBased part and advances the line's runtime hours
     * enough to cross the 80% at-risk threshold (see PartLifetimeService::
     * AT_RISK_THRESHOLD_PERCENT), returning the resulting installation.
     */
    private function installAtRiskPart(): PartInstallation
    {
        $lifecycle = app(PartLifecycleService::class);
        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        $this->line->update(['runtime_hours' => 85]); // 85/100 = 85% used, above the 80% threshold

        return $installation->fresh();
    }

    // --- Trigger creates a normal, immediately-executable Task ---

    public function test_life_based_trigger_creates_a_normal_task(): void
    {
        $this->installAtRiskPart();

        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $task = Task::where('equipment_id', $this->equipment->id)->first();
        $this->assertNotNull($task);
        $this->assertNull($task->task_library_id);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(1, $task->partChecks()->count());
        $this->assertSame($this->part->id, $task->partChecks()->first()->part_id);
    }

    public function test_generated_life_based_task_has_no_wo_of_any_kind(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $this->assertNull($task->work_order_id);
    }

    // --- Execution / replacement-lifecycle ---

    public function test_task_execution_works_directly_with_no_planning_step(): void
    {
        $installation = $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $check = $task->partChecks()->firstOrFail();

        $completed = app(TaskService::class)->complete($task, $this->user, null, null, null, [
            [
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $installation->id,
            ],
        ]);

        $this->assertSame(TaskStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_existing_replacement_lifecycle_flow_still_works_after_life_based_completion(): void
    {
        $installation = $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $check = $task->partChecks()->firstOrFail();

        app(TaskService::class)->complete($task, $this->user, null, null, null, [
            [
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $installation->id,
            ],
        ]);

        $this->assertNotNull($installation->fresh()->removed_at);
        $newInstallation = $this->equipment->activeInstallation($this->part->id);
        $this->assertNotNull($newInstallation);
        $this->assertNotSame($installation->id, $newInstallation->id);
    }

    // --- Duplicate protection under repeated catch-up ---

    public function test_repeated_scheduling_catchup_does_not_duplicate_the_life_based_task(): void
    {
        $this->installAtRiskPart();

        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();
        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();
        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();

        $this->assertSame(1, Task::where('equipment_id', $this->equipment->id)->count());
    }

    // --- Existing threshold behavior unchanged ---

    public function test_existing_lifetime_threshold_behavior_remains_unchanged(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        // Only 50% used — below the 80% at-risk threshold.
        $this->line->update(['runtime_hours' => 50]);

        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $this->assertSame(0, Task::where('equipment_id', $this->equipment->id)->count());
    }

    // --- Per-line average weekly operating hours ---

    public function test_due_date_projection_uses_the_installations_own_line_override(): void
    {
        $installation = $this->installAtRiskPart();
        $lifetime = app(PartLifetimeService::class);

        $this->line->update(['avg_weekly_operating_hours' => 10]); // slow line: more weeks needed
        $slowDueDate = $lifetime->computeAutoScheduleDueDate($installation->fresh());

        $this->line->update(['avg_weekly_operating_hours' => 500]); // fast line: fewer weeks needed
        $fastDueDate = $lifetime->computeAutoScheduleDueDate($installation->fresh());

        // A line that runs far more hours/week wears the remaining lifetime
        // out sooner, so its projected due date must land no later than the
        // slow line's — confirms the override actually drives the
        // calculation instead of both using one flat company-wide number.
        $this->assertTrue($fastDueDate->lte($slowDueDate));
    }

    public function test_due_date_projection_falls_back_to_company_default_when_line_has_no_override(): void
    {
        $installation = $this->installAtRiskPart();
        $this->line->update(['avg_weekly_operating_hours' => null]);

        // Should not throw, and should compute using the 85/week company default.
        $dueDate = app(PartLifetimeService::class)->computeAutoScheduleDueDate($installation->fresh());

        $this->assertNotNull($dueDate);
    }

    // --- Outside Line deferral ---

    public function test_outside_line_equipment_without_runtime_source_does_not_produce_incorrect_life_based_schedule(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $outsideLineEquipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);

        $lifecycle->install($outsideLineEquipment, ['part_id' => $this->part->id], $this->user);

        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $this->assertSame(0, Task::where('equipment_id', $outsideLineEquipment->id)->count());
    }

    public function test_outside_line_equipment_is_not_included_in_at_risk_installations(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $outsideLineEquipment = Equipment::factory()->create(['machine_id' => null, 'branch_id' => $this->branch->id]);
        $lifecycle->install($outsideLineEquipment, ['part_id' => $this->part->id], $this->user);

        $atRisk = app(PartLifetimeService::class)->atRiskInstallations($this->branch->id);

        $this->assertTrue($atRisk->every(fn (PartInstallation $installation) => $installation->equipment_id !== $outsideLineEquipment->id));
    }
}
