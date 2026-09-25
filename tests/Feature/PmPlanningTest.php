<?php

namespace Tests\Feature;

use App\Enums\MaintenanceWorkOrderStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\MaintenanceWorkOrder;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B Step 4 — the PM planning layer. Task Library / Part Lifetime /
 * ad-hoc scheduling still create one Task at a time, NEED_PLANNING by
 * default (see the "existing scheduling behavior" tests at the bottom) -
 * this file only covers the NEW grouping-into-a-WO layer on top of that.
 */
class PmPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private Equipment $equipment;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $this->line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);
    }

    private function makeTask(array $overrides = []): Task
    {
        return Task::factory()->create(array_merge([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
        ], $overrides));
    }

    // --- Planning states (Step 2 target model, derived not persisted) ---

    public function test_a_freshly_generated_task_needs_planning(): void
    {
        $task = $this->makeTask();

        $this->assertSame('need_planning', $task->planningState());
    }

    public function test_a_task_grouped_into_a_wo_reports_in_wo(): void
    {
        $task = $this->makeTask();

        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$task->id],
        ])->assertCreated();

        $this->assertSame('in_wo', $task->fresh()->planningState());
    }

    public function test_a_completed_task_reports_completed_regardless_of_wo_membership(): void
    {
        $task = $this->makeTask(['status' => TaskStatus::Completed]);

        $this->assertSame('completed', $task->planningState());
    }

    // --- Grouping ---

    public function test_multiple_tasks_can_belong_to_one_wo(): void
    {
        $taskA = $this->makeTask();
        $taskB = $this->makeTask();

        $response = $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$taskA->id, $taskB->id]],
        );

        $response->assertCreated();
        $workOrderId = $response->json('data.id');

        $this->assertSame($workOrderId, $taskA->fresh()->maintenance_work_order_id);
        $this->assertSame($workOrderId, $taskB->fresh()->maintenance_work_order_id);
        $this->assertSame(2, $response->json('data.task_count'));
        $this->assertSame('planning', $response->json('data.status'));
    }

    public function test_one_task_cannot_belong_to_multiple_wos(): void
    {
        $task = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$task->id],
        ])->assertCreated();
        $firstWorkOrderId = $task->fresh()->maintenance_work_order_id;

        $response = $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$task->id]],
        );

        $response->assertStatus(409);
        $this->assertSame($firstWorkOrderId, $task->fresh()->maintenance_work_order_id);
        $this->assertSame(1, MaintenanceWorkOrder::count());
    }

    /** Completed tasks cannot be accidentally regrouped. */
    public function test_a_completed_task_cannot_be_grouped_into_a_wo(): void
    {
        $task = $this->makeTask(['status' => TaskStatus::Completed]);

        $response = $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$task->id]],
        );

        $response->assertStatus(422);
        $this->assertNull($task->fresh()->maintenance_work_order_id);
    }

    /** An in_progress task (already being worked) isn't planning-eligible either. */
    public function test_an_in_progress_task_cannot_be_grouped_into_a_wo(): void
    {
        $task = $this->makeTask(['status' => TaskStatus::InProgress]);

        $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$task->id]],
        )->assertStatus(422);
    }

    public function test_needing_planning_excludes_grouped_completed_and_in_progress_tasks(): void
    {
        $needsPlanning = $this->makeTask();
        $grouped = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$grouped->id],
        ])->assertCreated();
        $completed = $this->makeTask(['status' => TaskStatus::Completed]);
        $inProgress = $this->makeTask(['status' => TaskStatus::InProgress]);

        $response = $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/tasks-needing-planning");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($needsPlanning->id));
        $this->assertFalse($ids->contains($grouped->id));
        $this->assertFalse($ids->contains($completed->id));
        $this->assertFalse($ids->contains($inProgress->id));
    }

    /** WO's own line_id/planned_date are only filled in when every grouped Task actually agrees. */
    public function test_wo_denormalizes_line_and_date_only_when_every_task_agrees(): void
    {
        $sameDate = now()->addWeek()->toDateString();
        $taskA = $this->makeTask(['due_date' => $sameDate]);
        $taskB = $this->makeTask(['due_date' => $sameDate]);

        $response = $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$taskA->id, $taskB->id]],
        )->assertCreated();

        $this->assertSame($this->line->id, $response->json('data.line_id'));
        $this->assertSame($sameDate, $response->json('data.planned_date'));

        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $otherMachine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        $taskC = $this->makeTask(['due_date' => $sameDate]);
        $taskD = $this->makeTask(['equipment_id' => $otherEquipment->id, 'due_date' => now()->addWeeks(2)->toDateString()]);

        $mixedResponse = $this->actingAs($this->engineer)->postJson(
            "/api/branches/{$this->branch->id}/maintenance-work-orders",
            ['task_ids' => [$taskC->id, $taskD->id]],
        )->assertCreated();

        $this->assertNull($mixedResponse->json('data.line_id'));
        $this->assertNull($mixedResponse->json('data.planned_date'));
    }

    // --- WO status lifecycle ---

    public function test_mark_ready_requires_planning_status_and_at_least_one_task(): void
    {
        $workOrder = MaintenanceWorkOrder::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->engineer)
            ->postJson("/api/maintenance-work-orders/{$workOrder->id}/mark-ready")
            ->assertStatus(422);

        $task = $this->makeTask(['maintenance_work_order_id' => $workOrder->id]);

        $this->actingAs($this->engineer)
            ->postJson("/api/maintenance-work-orders/{$workOrder->id}/mark-ready")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->actingAs($this->engineer)
            ->postJson("/api/maintenance-work-orders/{$workOrder->id}/mark-ready")
            ->assertStatus(422);

        $this->assertNotNull($task);
    }

    /** Execution compatibility (Step 6): starting a Task inside a WO works exactly as before, and also flips the WO to in_progress. */
    public function test_starting_a_task_inside_a_ready_wo_marks_the_wo_in_progress(): void
    {
        $task = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$task->id],
        ])->assertCreated();
        $workOrder = MaintenanceWorkOrder::first();
        $workOrder->markReady();

        $this->actingAs($this->engineer)->postJson("/api/tasks/{$task->id}/start")->assertOk();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame(MaintenanceWorkOrderStatus::InProgress, $workOrder->fresh()->status);
    }

    /** Execution compatibility (Step 6): completing every Task inside a WO works exactly as before, and completes the WO too. */
    public function test_completing_every_task_in_a_wo_marks_the_wo_completed(): void
    {
        $taskA = $this->makeTask();
        $taskB = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$taskA->id, $taskB->id],
        ])->assertCreated();
        $workOrder = MaintenanceWorkOrder::first();
        $workOrder->markReady();

        $this->actingAs($this->engineer)->postJson("/api/tasks/{$taskA->id}/complete", [])->assertOk();
        // One task done, one still pending: real progress has been made on
        // the package, so it's no longer just Ready — not Completed either,
        // since taskB isn't done yet.
        $this->assertSame(MaintenanceWorkOrderStatus::InProgress, $workOrder->fresh()->status);

        $this->actingAs($this->engineer)->postJson("/api/tasks/{$taskB->id}/complete", [])->assertOk();
        $this->assertSame(MaintenanceWorkOrderStatus::Completed, $workOrder->fresh()->status);
    }

    /** A WO left in Planning never auto-advances just because a task inside it changes — only an explicit markReady() moves it. */
    public function test_a_wo_still_in_planning_does_not_auto_advance(): void
    {
        $task = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$task->id],
        ])->assertCreated();
        $workOrder = MaintenanceWorkOrder::first();

        $this->actingAs($this->engineer)->postJson("/api/tasks/{$task->id}/start")->assertOk();

        $this->assertSame(MaintenanceWorkOrderStatus::Planning, $workOrder->fresh()->status);
    }

    // --- Existing scheduling behavior remains intact (Step 5's fallback shares this exact same entry point) ---

    /** The PM Calendar's data source still returns every task in the branch, WO or no WO. */
    public function test_pm_schedule_endpoint_still_returns_all_tasks(): void
    {
        $unplanned = $this->makeTask();
        $grouped = $this->makeTask();
        $this->actingAs($this->engineer)->postJson("/api/branches/{$this->branch->id}/maintenance-work-orders", [
            'task_ids' => [$grouped->id],
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/pm-tasks");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unplanned->id));
        $this->assertTrue($ids->contains($grouped->id));
    }

    /** Step 5: an overdue, still-unplanned task gets swept into a WO automatically the next time the PM Calendar loads — no manual grouping needed. */
    public function test_overdue_unplanned_task_is_auto_grouped_on_pm_schedule_load(): void
    {
        $overdue = $this->makeTask(['due_date' => now()->subDay()->toDateString()]);
        $notYetDue = $this->makeTask(['due_date' => now()->addWeek()->toDateString()]);

        $this->assertNull($overdue->maintenance_work_order_id);

        $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();

        $this->assertNotNull($overdue->fresh()->maintenance_work_order_id);
        $this->assertNull($notYetDue->fresh()->maintenance_work_order_id, 'a task not yet due must never be swept up.');

        $autoWorkOrder = MaintenanceWorkOrder::findOrFail($overdue->fresh()->maintenance_work_order_id);
        $this->assertSame(MaintenanceWorkOrderStatus::Planning, $autoWorkOrder->status);
        $this->assertNull($autoWorkOrder->created_by, 'system-generated, not attributed to whoever merely loaded the calendar.');
    }

    /** Calling the PM Calendar endpoint twice must never double-group the same overdue task into two different WOs. */
    public function test_auto_grouping_is_idempotent_across_repeated_pm_schedule_loads(): void
    {
        $overdue = $this->makeTask(['due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();
        $firstWorkOrderId = $overdue->fresh()->maintenance_work_order_id;

        $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();

        $this->assertSame($firstWorkOrderId, $overdue->fresh()->maintenance_work_order_id);
        $this->assertSame(1, MaintenanceWorkOrder::count());
    }
}
