<?php

namespace Tests\Feature;

use App\Enums\FindingResult;
use App\Enums\FindingSourceType;
use App\Enums\MaintenanceDecisionType;
use App\Enums\ReplacementRequestStatus;
use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MaintenanceDecision;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2, Step 2 — MaintenanceDecision foundation only. No physical
 * execution: no Task/PartReplacementRequest is ever created by this layer
 * yet, no PartLifecycleService/StockMovementService/PartReplacementService
 * call, no Approval touch. These tests only prove the standalone
 * model/migration/enum contract is sound.
 */
class MaintenanceDecisionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    private Part $part;

    private User $reporter;

    private User $decider;

    private Finding $finding;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->reporter = User::factory()->create();
        $this->decider = User::factory()->create();

        $task = Task::factory()->create(['equipment_id' => $this->equipment->id]);
        $taskPartCheck = TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => 1,
        ]);

        $this->finding = Finding::create([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $taskPartCheck->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => $this->part->id,
            'description' => 'Bearing temperature 85°C saat inspeksi.',
            'result' => FindingResult::ActionRequired,
            'reported_by' => $this->reporter->id,
            'reported_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBaseDecision(array $overrides = []): MaintenanceDecision
    {
        return MaintenanceDecision::create(array_merge([
            'finding_id' => $this->finding->id,
            'decision' => MaintenanceDecisionType::Monitor,
            'decided_by' => $this->decider->id,
            'decided_at' => now(),
        ], $overrides));
    }

    /** A: create Decision — Finding valid + User valid. */
    public function test_a_create_decision_with_valid_finding_and_user(): void
    {
        $decision = $this->makeBaseDecision();

        $this->assertNotNull($decision->id);
        $this->assertSame($this->finding->id, $decision->finding_id);
        $this->assertSame($this->decider->id, $decision->decided_by);
    }

    /** B: decision enum — all 6 valid, invalid rejected by cast. */
    public function test_b_decision_enum_accepts_only_known_values(): void
    {
        foreach (MaintenanceDecisionType::cases() as $case) {
            // Unique finding_id means one Decision per Finding, and unique
            // (source_type, source_id) means one Finding per source — make a
            // fresh Finding backed by a fresh TaskPartCheck per case.
            $task = Task::factory()->create(['equipment_id' => $this->equipment->id]);
            $taskPartCheck = TaskPartCheck::create([
                'task_id' => $task->id,
                'part_id' => $this->part->id,
                'quantity_planned' => 1,
            ]);

            $finding = Finding::create([
                'source_type' => FindingSourceType::TaskPartCheck,
                'source_id' => $taskPartCheck->id,
                'equipment_id' => $this->equipment->id,
                'result' => FindingResult::Monitor,
                'description' => 'Observasi uji '.$case->value,
                'reported_by' => $this->reporter->id,
                'reported_at' => now(),
            ]);

            $decision = MaintenanceDecision::create([
                'finding_id' => $finding->id,
                'decision' => $case,
                'decided_by' => $this->decider->id,
                'decided_at' => now(),
            ]);

            $this->assertSame($case, $decision->fresh()->decision);
        }

        $this->expectException(\ValueError::class);
        $this->makeBaseDecision(['decision' => 'unknown_decision']);
    }

    /** C: Finding required — no finding_id must not persist. */
    public function test_c_decision_without_finding_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        MaintenanceDecision::create([
            'decision' => MaintenanceDecisionType::NoAction,
            'decided_by' => $this->decider->id,
            'decided_at' => now(),
        ]);
    }

    /** D: decided_by required — anonymous Decision must not persist. */
    public function test_d_decision_without_decided_by_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        MaintenanceDecision::create([
            'finding_id' => $this->finding->id,
            'decision' => MaintenanceDecisionType::NoAction,
            'decided_at' => now(),
        ]);
    }

    /** E: decided_at is persisted as the actual decision timestamp. */
    public function test_e_decided_at_is_persisted(): void
    {
        $moment = now()->subHour()->startOfSecond();
        $decision = $this->makeBaseDecision(['decided_at' => $moment]);

        $this->assertTrue($decision->fresh()->decided_at->equalTo($moment));
    }

    /** F: notes nullable. */
    public function test_f_notes_is_nullable(): void
    {
        $decision = $this->makeBaseDecision();

        $this->assertNull($decision->fresh()->notes);
    }

    /** G: resulting_task_id nullable. */
    public function test_g_resulting_task_is_nullable(): void
    {
        $decision = $this->makeBaseDecision();

        $this->assertNull($decision->fresh()->resulting_task_id);
    }

    /** H: resulting_replacement_request_id nullable. */
    public function test_h_resulting_replacement_request_is_nullable(): void
    {
        $decision = $this->makeBaseDecision();

        $this->assertNull($decision->fresh()->resulting_replacement_request_id);
    }

    /** I: FK integrity — resulting_task_id/resulting_replacement_request_id must reference real rows. */
    public function test_i_resulting_task_must_reference_an_existing_task(): void
    {
        $this->expectException(QueryException::class);
        $this->makeBaseDecision(['resulting_task_id' => 999999]);
    }

    public function test_i_resulting_replacement_request_must_reference_an_existing_request(): void
    {
        $this->expectException(QueryException::class);
        $this->makeBaseDecision(['resulting_replacement_request_id' => 999999]);
    }

    public function test_i_resulting_task_resolves_when_it_references_a_real_task(): void
    {
        $task = Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Pending]);
        $decision = $this->makeBaseDecision(['resulting_task_id' => $task->id]);

        $this->assertTrue($decision->fresh()->resultingTask->is($task));
    }

    public function test_i_resulting_replacement_request_resolves_when_it_references_a_real_request(): void
    {
        $request = PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);
        $decision = $this->makeBaseDecision(['resulting_replacement_request_id' => $request->id]);

        $this->assertTrue($decision->fresh()->resultingReplacementRequest->is($request));
    }

    /** J: unique Finding-Decision — a second Decision for the same Finding must be rejected. */
    public function test_j_a_finding_cannot_have_two_decisions(): void
    {
        $this->makeBaseDecision();

        $this->expectException(QueryException::class);
        $this->makeBaseDecision(['decision' => MaintenanceDecisionType::Escalate]);
    }

    /** K: relations resolve both ways. */
    public function test_k_relations_resolve(): void
    {
        $task = Task::factory()->create(['equipment_id' => $this->equipment->id]);
        $request = PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);

        $decision = $this->makeBaseDecision([
            'resulting_task_id' => $task->id,
            'resulting_replacement_request_id' => $request->id,
        ]);

        $this->assertTrue($decision->finding->is($this->finding));
        $this->assertTrue($decision->decidedBy->is($this->decider));
        $this->assertTrue($decision->resultingTask->is($task));
        $this->assertTrue($decision->resultingReplacementRequest->is($request));
        $this->assertTrue($this->finding->fresh()->decision->is($decision));
    }

    /** L: creating a Decision has no side effects on the rest of the system. */
    public function test_l_creating_a_decision_has_no_side_effects(): void
    {
        $taskCountBefore = Task::count();
        $requestCountBefore = PartReplacementRequest::count();
        $ledgerCountBefore = StockLedger::count();
        $sourceBefore = $this->finding->resolveSource();

        $this->makeBaseDecision(['decision' => MaintenanceDecisionType::ReplacePart]);

        $this->assertSame($taskCountBefore, Task::count());
        $this->assertSame($requestCountBefore, PartReplacementRequest::count());
        $this->assertSame($ledgerCountBefore, StockLedger::count());
        $this->assertEquals($sourceBefore->fresh()->getAttributes(), $this->finding->resolveSource()->fresh()->getAttributes());
    }
}
