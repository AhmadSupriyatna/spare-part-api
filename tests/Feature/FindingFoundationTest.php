<?php

namespace Tests\Feature;

use App\Enums\FindingResult;
use App\Enums\FindingSeverity;
use App\Enums\FindingSourceType;
use App\Enums\ReplacementRequestStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2, Step 1 — Finding foundation only. No Decision, no TaskService
 * integration, no automatic creation from any existing flow: these tests
 * only prove the standalone model/migration/enum contract is sound.
 */
class FindingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    private Part $part;

    private User $user;

    private TaskPartCheck $taskPartCheck;

    private PartReplacementRequest $replacementRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->user = User::factory()->create();

        $task = Task::factory()->create(['equipment_id' => $this->equipment->id]);
        $this->taskPartCheck = TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => 1,
        ]);

        $this->replacementRequest = PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);
    }

    /** Test 1: create Finding with equipment-only. */
    public function test_create_finding_with_equipment_only(): void
    {
        $finding = Finding::create([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => null,
            'description' => 'Vibration equipment terasa abnormal saat inspeksi rutin.',
            'result' => FindingResult::Monitor,
            'severity' => FindingSeverity::Low,
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ]);

        $this->assertNotNull($finding->id);
        $this->assertNull($finding->part_id);
        $this->assertSame($this->equipment->id, $finding->equipment_id);
    }

    /** Test 2: create Finding with equipment + part. */
    public function test_create_finding_with_equipment_and_part(): void
    {
        $finding = Finding::create([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => $this->part->id,
            'description' => 'Bearing temperature 85°C saat inspeksi.',
            'result' => FindingResult::ActionRequired,
            'severity' => FindingSeverity::High,
            'recommendation' => 'Replace bearing within next maintenance window.',
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ]);

        $this->assertSame($this->part->id, $finding->fresh()->part_id);
    }

    /** Test 3: result enum only accepts pass|monitor|action_required|fail. */
    public function test_result_only_accepts_known_values(): void
    {
        foreach (FindingResult::cases() as $case) {
            // A fresh TaskPartCheck per case — one source can only ever have
            // one Finding (unique(source_type, source_id)), so this loop
            // can't reuse $this->taskPartCheck across iterations.
            $finding = $this->makeBaseFinding(['source_id' => $this->makeTaskPartCheck()->id, 'result' => $case]);
            $this->assertSame($case, $finding->fresh()->result);
        }

        $this->expectException(\ValueError::class);
        $this->makeBaseFinding(['source_id' => $this->makeTaskPartCheck()->id, 'result' => 'unknown_result']);
    }

    /** Test 4: severity enum only accepts low|medium|high|critical. */
    public function test_severity_only_accepts_known_values(): void
    {
        foreach (FindingSeverity::cases() as $case) {
            $finding = $this->makeBaseFinding([
                'source_id' => $this->makeTaskPartCheck()->id,
                'result' => FindingResult::Fail,
                'severity' => $case,
            ]);
            $this->assertSame($case, $finding->fresh()->severity);
        }

        $this->expectException(\ValueError::class);
        $this->makeBaseFinding(['source_id' => $this->makeTaskPartCheck()->id, 'severity' => 'unknown_severity']);
    }

    /** Test 5: part_id, recommendation, severity are nullable. */
    public function test_nullable_fields_can_be_omitted(): void
    {
        $finding = Finding::create([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => null,
            'description' => 'Equipment berjalan normal saat inspeksi rutin.',
            'result' => FindingResult::Pass,
            'severity' => null,
            'recommendation' => null,
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ]);

        $fresh = $finding->fresh();
        $this->assertNull($fresh->part_id);
        $this->assertNull($fresh->severity);
        $this->assertNull($fresh->recommendation);
    }

    /** Test 6: relations resolve correctly. */
    public function test_relations_resolve(): void
    {
        $finding = $this->makeBaseFinding(['part_id' => $this->part->id]);

        $this->assertTrue($finding->equipment->is($this->equipment));
        $this->assertTrue($finding->part->is($this->part));
        $this->assertTrue($finding->reporter->is($this->user));
    }

    /** Test 7: source_type only accepts task_part_check|part_replacement_request, and each relation resolves. */
    public function test_source_type_only_accepts_known_values_and_resolves(): void
    {
        $fromChecklist = $this->makeBaseFinding([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
        ]);
        $this->assertTrue($fromChecklist->taskPartCheck->is($this->taskPartCheck));
        $this->assertTrue($fromChecklist->resolveSource()->is($this->taskPartCheck));

        $fromBreakdown = $this->makeBaseFinding([
            'source_type' => FindingSourceType::PartReplacementRequest,
            'source_id' => $this->replacementRequest->id,
        ]);
        $this->assertTrue($fromBreakdown->partReplacementRequest->is($this->replacementRequest));
        $this->assertTrue($fromBreakdown->resolveSource()->is($this->replacementRequest));

        $this->expectException(\ValueError::class);
        $this->makeBaseFinding(['source_type' => 'inspection_report']);
    }

    /**
     * No automatic Finding is created anywhere in the app yet — this step
     * only builds the standalone model. Confirmed by the fact that nothing
     * in setUp() (creating a Task, TaskPartCheck, or PartReplacementRequest)
     * produces a Finding on its own.
     */
    public function test_no_finding_is_created_automatically_by_existing_flows(): void
    {
        $this->assertSame(0, Finding::count());
    }

    /** Test 8: duplicate (task_part_check, source_id) is rejected by the DB. */
    public function test_duplicate_source_is_rejected_for_task_part_check(): void
    {
        $this->makeBaseFinding([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
        ]);

        $this->expectException(QueryException::class);

        try {
            $this->makeBaseFinding([
                'source_type' => FindingSourceType::TaskPartCheck,
                'source_id' => $this->taskPartCheck->id,
            ]);
        } finally {
            $this->assertSame(1, Finding::count());
        }
    }

    /** Test 9: duplicate (part_replacement_request, source_id) is rejected by the DB. */
    public function test_duplicate_source_is_rejected_for_part_replacement_request(): void
    {
        $this->makeBaseFinding([
            'source_type' => FindingSourceType::PartReplacementRequest,
            'source_id' => $this->replacementRequest->id,
        ]);

        $this->expectException(QueryException::class);

        try {
            $this->makeBaseFinding([
                'source_type' => FindingSourceType::PartReplacementRequest,
                'source_id' => $this->replacementRequest->id,
            ]);
        } finally {
            $this->assertSame(1, Finding::count());
        }
    }

    /**
     * Test 10: the constraint is genuinely composite — the same numeric
     * source_id may exist under BOTH source types at once, so a
     * task_part_check row and a part_replacement_request row that happen to
     * share an id do not collide.
     */
    public function test_same_source_id_is_valid_under_both_source_types(): void
    {
        $sharedId = min($this->taskPartCheck->id, $this->replacementRequest->id);

        $fromChecklist = $this->makeBaseFinding([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $sharedId,
        ]);

        $fromBreakdown = $this->makeBaseFinding([
            'source_type' => FindingSourceType::PartReplacementRequest,
            'source_id' => $sharedId,
        ]);

        $this->assertNotNull($fromChecklist->id);
        $this->assertNotNull($fromBreakdown->id);
        $this->assertSame(2, Finding::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBaseFinding(array $overrides = []): Finding
    {
        return Finding::create(array_merge([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $this->taskPartCheck->id,
            'equipment_id' => $this->equipment->id,
            'part_id' => null,
            'description' => 'Observasi uji.',
            'result' => FindingResult::Monitor,
            'severity' => null,
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ], $overrides));
    }

    private function makeTaskPartCheck(): TaskPartCheck
    {
        $task = Task::factory()->create(['equipment_id' => $this->equipment->id]);

        return TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => 1,
        ]);
    }
}
