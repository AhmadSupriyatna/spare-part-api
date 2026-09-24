<?php

namespace Tests\Feature;

use App\Enums\FindingResult;
use App\Enums\FindingSourceType;
use App\Enums\MaintenanceDecisionType;
use App\Enums\PartReplacementStrategy;
use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidFollowUpQuantityException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MaintenanceDecision;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\MaintenanceDecisionService;
use App\Services\PartLifecycleService;
use App\Services\PartReplacementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2, Step 4 — the first real Finding -> MaintenanceDecision ->
 * Follow-up integration. Physical execution (install/remove/stock) is never
 * triggered here except through the pre-existing, unmodified
 * PartReplacementService::approve() call in the "replacement event
 * semantics" group, which only proves REPLACE_PART's Pending request is a
 * real, executable request rather than a parallel structure.
 */
class MaintenanceDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private Part $part;

    private User $engineer;

    private User $adminSparePart;

    private User $supervisor;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->engineer = $this->makeUser(UserRole::Engineer);
        $this->adminSparePart = $this->makeUser(UserRole::AdminSparePart);
        $this->supervisor = $this->makeUser(UserRole::Supervisor);
        $this->superadmin = $this->makeUser(UserRole::Superadmin);
    }

    private function makeUser(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->branches()->attach($this->branch->id);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFinding(array $overrides = []): Finding
    {
        $quantityPlanned = $overrides['quantity_planned'] ?? 1;
        unset($overrides['quantity_planned']);

        $task = Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Completed]);
        $check = TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => $quantityPlanned,
        ]);

        return Finding::create(array_merge([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $check->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => $this->part->id,
            'description' => 'Bearing mulai terdengar kasar.',
            'result' => FindingResult::Monitor,
            'reported_by' => $this->engineer->id,
            'reported_at' => now(),
        ], $overrides));
    }

    private function installActiveUnit(): void
    {
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $this->part->id], $this->engineer);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decide(User $actor, Finding $finding, array $payload): TestResponse
    {
        return $this->actingAs($actor)->postJson("/api/findings/{$finding->id}/decision", $payload);
    }

    // ---- Authorization ----

    public function test_supervisor_can_create_decision(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, ['decision' => 'no_action'])->assertOk();
        $this->assertSame(1, MaintenanceDecision::count());
    }

    public function test_superadmin_can_create_decision(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->superadmin, $finding, ['decision' => 'no_action'])->assertOk();
        $this->assertSame(1, MaintenanceDecision::count());
    }

    public function test_engineer_cannot_create_decision(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->engineer, $finding, ['decision' => 'no_action'])->assertForbidden();
        $this->assertSame(0, MaintenanceDecision::count());
    }

    public function test_admin_spare_part_cannot_create_decision(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->adminSparePart, $finding, ['decision' => 'no_action'])->assertForbidden();
        $this->assertSame(0, MaintenanceDecision::count());
    }

    // ---- One Decision only ----

    public function test_same_finding_cannot_receive_second_decision(): void
    {
        $finding = $this->makeFinding();
        $this->decide($this->supervisor, $finding, ['decision' => 'no_action'])->assertOk();

        $this->decide($this->supervisor, $finding, ['decision' => 'monitor'])->assertStatus(409);
        $this->assertSame(1, MaintenanceDecision::count());
    }

    // ---- NO_ACTION ----

    public function test_no_action_creates_decision_without_resulting_record(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'no_action',
            'notes' => 'Kondisi masih dalam batas normal.',
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertSame(MaintenanceDecisionType::NoAction, $decision->decision);
        $this->assertNull($decision->resulting_task_id);
        $this->assertNull($decision->resulting_replacement_request_id);
    }

    // ---- MONITOR ----

    public function test_monitor_without_follow_up(): void
    {
        $finding = $this->makeFinding();
        $tasksBefore = Task::count();

        $this->decide($this->supervisor, $finding, ['decision' => 'monitor'])->assertOk();

        $this->assertNull(MaintenanceDecision::first()->resulting_task_id);
        $this->assertSame($tasksBefore, Task::count());
    }

    public function test_monitor_with_task_library_follow_up(): void
    {
        $finding = $this->makeFinding();
        $library = TaskLibrary::create(['equipment_id' => $this->equipment->id, 'title' => 'Inspeksi Bearing']);

        $this->decide($this->supervisor, $finding, [
            'decision' => 'monitor',
            'follow_up_task_library_id' => $library->id,
            'follow_up_due_date' => now()->addWeek()->toDateString(),
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertNotNull($decision->resulting_task_id);
        $task = Task::find($decision->resulting_task_id);
        $this->assertSame($library->id, $task->task_library_id);
        $this->assertSame($this->equipment->id, $task->equipment_id);
    }

    public function test_monitor_with_ad_hoc_follow_up(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'monitor',
            'follow_up_title' => 'Cek ulang bearing bulan depan',
            'follow_up_due_date' => now()->addMonth()->toDateString(),
            'follow_up_notes' => 'Pantau suara kasar.',
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $task = Task::find($decision->resulting_task_id);
        $this->assertNull($task->task_library_id);
        $this->assertSame('Cek ulang bearing bulan depan', $task->title);
        $this->assertSame($this->equipment->id, $task->equipment_id);
        $this->assertSame(1, TaskPartCheck::where('task_id', $task->id)->count());
    }

    // ---- SCHEDULE_MAINTENANCE ----

    public function test_schedule_maintenance_requires_a_follow_up(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, ['decision' => 'schedule_maintenance'])->assertUnprocessable();
        $this->assertSame(0, MaintenanceDecision::count());
    }

    public function test_schedule_maintenance_with_task_library_follow_up(): void
    {
        $finding = $this->makeFinding();
        $library = TaskLibrary::create(['equipment_id' => $this->equipment->id, 'title' => 'Ganti Bearing Terjadwal']);

        $this->decide($this->supervisor, $finding, [
            'decision' => 'schedule_maintenance',
            'follow_up_task_library_id' => $library->id,
            'follow_up_due_date' => now()->addWeeks(2)->toDateString(),
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertNotNull($decision->resulting_task_id);
        $this->assertSame($library->id, Task::find($decision->resulting_task_id)->task_library_id);
    }

    public function test_schedule_maintenance_with_ad_hoc_follow_up(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'schedule_maintenance',
            'follow_up_title' => 'Ganti bearing pada maintenance window berikutnya',
            'follow_up_due_date' => now()->addWeeks(2)->toDateString(),
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $task = Task::find($decision->resulting_task_id);
        $this->assertNotNull($task);
        $this->assertNull($task->task_library_id);
    }

    // ---- Task Library ownership ----

    public function test_task_library_belonging_to_different_equipment_is_rejected(): void
    {
        $otherEquipment = Equipment::factory()->create([
            'machine_id' => Machine::factory()->create([
                'line_id' => ProductionLine::factory()->create(['branch_id' => $this->branch->id])->id,
            ])->id,
        ]);
        $foreignLibrary = TaskLibrary::create(['equipment_id' => $otherEquipment->id, 'title' => 'Recipe Equipment Lain']);

        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'monitor',
            'follow_up_task_library_id' => $foreignLibrary->id,
            'follow_up_due_date' => now()->addWeek()->toDateString(),
        ])->assertUnprocessable();

        $this->assertSame(0, MaintenanceDecision::count());
    }

    // ---- REPLACE_PART ----

    public function test_replace_part_creates_pending_replacement_request(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();
        $ledgerBefore = StockLedger::count();

        $this->decide($this->supervisor, $finding, ['decision' => 'replace_part'])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertNotNull($decision->resulting_replacement_request_id);

        $request = PartReplacementRequest::find($decision->resulting_replacement_request_id);
        $this->assertSame(ReplacementRequestStatus::Pending, $request->status);
        $this->assertSame(ReplacementRequestEventType::Maintenance, $request->event_type);
        $this->assertSame($this->equipment->id, $request->equipment_id);
        $this->assertSame($this->part->id, $request->part_id);
        $this->assertSame($this->supervisor->name, $request->requested_by_name);

        // No physical execution happened yet — that's still Approval's job.
        $this->assertSame($ledgerBefore, StockLedger::count());
    }

    public function test_replace_part_quantity_defaults_from_task_part_check_quantity_planned(): void
    {
        $this->installActiveUnit();
        $this->installActiveUnit();
        $finding = $this->makeFinding(['quantity_planned' => 2]);

        $this->decide($this->supervisor, $finding, ['decision' => 'replace_part'])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertSame(2, PartReplacementRequest::find($decision->resulting_replacement_request_id)->quantity_used);
    }

    public function test_supervisor_can_override_replacement_quantity(): void
    {
        $this->installActiveUnit();
        $this->installActiveUnit();
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'replace_part',
            'follow_up_quantity' => 2,
        ])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertSame(2, PartReplacementRequest::find($decision->resulting_replacement_request_id)->quantity_used);
    }

    public function test_replace_part_quantity_above_active_installation_count_is_rejected(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, [
            'decision' => 'replace_part',
            'follow_up_quantity' => 5,
        ])->assertUnprocessable();

        $this->assertSame(0, MaintenanceDecision::count());
    }

    public function test_finding_without_part_cannot_be_replace_part(): void
    {
        $finding = $this->makeFinding(['part_id' => null]);

        $this->decide($this->supervisor, $finding, ['decision' => 'replace_part'])->assertUnprocessable();
        $this->assertSame(0, MaintenanceDecision::count());
    }

    // ---- REPAIR_PART ----

    public function test_repair_part_only_records_decision(): void
    {
        $finding = $this->makeFinding();
        $tasksBefore = Task::count();
        $requestsBefore = PartReplacementRequest::count();
        $ledgerBefore = StockLedger::count();

        $this->decide($this->supervisor, $finding, ['decision' => 'repair_part'])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertSame(MaintenanceDecisionType::RepairPart, $decision->decision);
        $this->assertNull($decision->resulting_task_id);
        $this->assertNull($decision->resulting_replacement_request_id);
        $this->assertSame($tasksBefore, Task::count());
        $this->assertSame($requestsBefore, PartReplacementRequest::count());
        $this->assertSame($ledgerBefore, StockLedger::count());
    }

    // ---- ESCALATE ----

    public function test_escalate_only_records_decision(): void
    {
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, ['decision' => 'escalate'])->assertOk();

        $decision = MaintenanceDecision::first();
        $this->assertSame(MaintenanceDecisionType::Escalate, $decision->decision);
        $this->assertNull($decision->resulting_task_id);
        $this->assertNull($decision->resulting_replacement_request_id);
    }

    // ---- Atomicity ----

    public function test_follow_up_task_creation_failure_rolls_back_decision(): void
    {
        $finding = $this->makeFinding();

        try {
            app(MaintenanceDecisionService::class)->decide($finding, $this->supervisor, [
                'decision' => 'monitor',
                'follow_up_task_library_id' => 999999,
                'follow_up_due_date' => now()->addWeek()->toDateString(),
            ]);
            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException) {
            // expected — bypasses StoreMaintenanceDecisionRequest's own
            // exists:task_libraries,id rule to exercise the transaction
            // boundary directly.
        }

        $this->assertSame(0, MaintenanceDecision::count());
    }

    public function test_replacement_request_creation_failure_rolls_back_decision(): void
    {
        $finding = $this->makeFinding(); // no active installation -> active count 0

        try {
            app(MaintenanceDecisionService::class)->decide($finding, $this->supervisor, [
                'decision' => 'replace_part',
            ]);
            $this->fail('Expected InvalidFollowUpQuantityException was not thrown.');
        } catch (InvalidFollowUpQuantityException) {
            // expected
        }

        $this->assertSame(0, MaintenanceDecision::count());
        $this->assertSame(0, PartReplacementRequest::count());
    }

    // ---- Replacement event semantics ----

    public function test_failure_based_strategy_still_produces_failure_event_type(): void
    {
        $this->assertSame(
            ReplacementRequestEventType::Failure,
            ReplacementRequestEventType::fromReplacementStrategy(PartReplacementStrategy::FailureBased)
        );
    }

    public function test_non_failure_strategy_still_produces_breakdown_event_type(): void
    {
        $this->assertSame(
            ReplacementRequestEventType::Breakdown,
            ReplacementRequestEventType::fromReplacementStrategy(PartReplacementStrategy::LifeBased)
        );
    }

    /**
     * Confirms REPLACE_PART's Pending request is a real, executable
     * PartReplacementRequest — not a parallel structure — by running it
     * through the existing, unmodified Approval pipeline.
     */
    public function test_maintenance_decision_replacement_request_is_executable_through_existing_approval(): void
    {
        $this->installActiveUnit();
        PartStock::factory()->create([
            'part_id' => $this->part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 10,
        ]);
        $finding = $this->makeFinding();

        $this->decide($this->supervisor, $finding, ['decision' => 'replace_part'])->assertOk();

        $decision = MaintenanceDecision::first();
        $request = PartReplacementRequest::find($decision->resulting_replacement_request_id);
        $this->assertSame(ReplacementRequestEventType::Maintenance, $request->event_type);

        app(PartReplacementService::class)->approve($request, $this->supervisor);

        $this->assertSame(ReplacementRequestStatus::Approved, $request->fresh()->status);
    }
}
