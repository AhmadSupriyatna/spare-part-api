<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\TaskAlreadyInWorkOrderException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\PartLifetimeService;
use App\Services\PmPlanningService;
use App\Services\PmSchedulingService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B Step 5 — Life Based Task -> PM Planning / WO. Confirms the audit
 * finding that Task Library (Step 4's PmPlanningService/needingPlanning/
 * createFromTasks) is already fully generic over Task origin — a Life Based
 * Task (PartLifetimeService::scheduleFromLifetime(), no task_library_id)
 * already participates in planning/grouping/execution exactly like a Task
 * Library one, with zero production-code change required. These tests exist
 * to pin that behavior down explicitly, since nothing previously exercised
 * PartLifetimeService at all.
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

    // --- Step 1 audit, pinned down: trigger creates a normal Task ---

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

    public function test_generated_task_starts_as_need_planning(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $this->assertSame('need_planning', $task->planningState());
    }

    public function test_generated_life_based_task_has_no_wo_initially(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $this->assertNull($task->maintenance_work_order_id);
        $this->assertNull($task->work_order_id);
    }

    // --- Step 2/3: planning + grouping ---

    public function test_life_based_task_appears_in_pm_planning(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $needingPlanning = app(PmPlanningService::class)->needingPlanning($this->branch->id);

        $this->assertTrue($needingPlanning->contains('id', $task->id));
    }

    public function test_life_based_task_can_be_grouped_into_a_maintenance_work_order(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $workOrder = app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);

        $this->assertSame($workOrder->id, $task->fresh()->maintenance_work_order_id);
        $this->assertSame('in_wo', $task->fresh()->planningState());
    }

    public function test_life_based_task_cannot_be_grouped_twice(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);

        $this->expectException(TaskAlreadyInWorkOrderException::class);
        app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);
    }

    /** A Life Based Task must not require Task Library membership to be grouped alongside a Scheduled Maintenance one. */
    public function test_life_based_task_can_be_grouped_with_a_scheduled_maintenance_task(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $lifeBasedTask = Task::where('equipment_id', $this->equipment->id)->firstOrFail();

        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-01',
            'title' => 'Cek Rutin',
            'is_active' => true,
        ]);
        $scheduledTask = app(PmSchedulingService::class)->schedule($library, now()->addDay()->toDateString(), null);

        $workOrder = app(PmPlanningService::class)->createFromTasks(
            $this->branch->id,
            [$lifeBasedTask->id, $scheduledTask->id],
            $this->user->id,
        );

        $this->assertSame($workOrder->id, $lifeBasedTask->fresh()->maintenance_work_order_id);
        $this->assertSame($workOrder->id, $scheduledTask->fresh()->maintenance_work_order_id);
        $this->assertSame(2, $workOrder->fresh()->tasks()->count());
    }

    // --- Execution / replacement-lifecycle compatibility ---

    public function test_task_execution_still_works_after_being_placed_in_a_wo(): void
    {
        $installation = $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();
        app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);

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
        app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);

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

    // --- Step 4: duplicate protection under repeated catch-up ---

    public function test_repeated_scheduling_catchup_does_not_duplicate_the_life_based_task(): void
    {
        $this->installAtRiskPart();

        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();
        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();
        $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();

        $this->assertSame(1, Task::where('equipment_id', $this->equipment->id)->count());
    }

    public function test_duplicate_protection_survives_the_task_being_grouped_into_a_wo(): void
    {
        $this->installAtRiskPart();
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);
        $task = Task::where('equipment_id', $this->equipment->id)->firstOrFail();
        app(PmPlanningService::class)->createFromTasks($this->branch->id, [$task->id], $this->user->id);

        // Catch-up runs again (e.g. a later PM Calendar page load) — must not
        // create a second Life Based task just because this one is now IN_WO.
        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $this->assertSame(1, Task::where('equipment_id', $this->equipment->id)->count());
    }

    // --- Step 1 audit, pinned down: existing threshold behavior unchanged ---

    public function test_existing_lifetime_threshold_behavior_remains_unchanged(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        // Only 50% used — below the 80% at-risk threshold.
        $this->line->update(['runtime_hours' => 50]);

        app(PartLifetimeService::class)->autoScheduleAllAtRisk($this->branch->id);

        $this->assertSame(0, Task::where('equipment_id', $this->equipment->id)->count());
    }

    // --- Step 5: Outside Line deferral ---

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
