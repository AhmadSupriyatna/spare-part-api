<?php

namespace Tests\Feature;

use App\Enums\FindingResult;
use App\Enums\FindingSeverity;
use App\Enums\FindingSourceType;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOldInstallationException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2, Step 3 — Checklist -> Finding integration only. No
 * MaintenanceDecision is created or consulted here; these tests only prove
 * that completing a PM checklist can optionally record a structured Finding
 * without changing Task/checklist completion semantics in any way.
 */
class ChecklistFindingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private Part $part;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();

        Role::findOrCreate(UserRole::Engineer->value, 'web');
        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::Engineer->value);
        $this->user->branches()->attach($this->branch->id);
    }

    private function makeTask(): Task
    {
        return Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
        ]);
    }

    private function makeCheck(Task $task, ?Part $part = null, int $quantityPlanned = 1): TaskPartCheck
    {
        return TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => ($part ?? $this->part)->id,
            'quantity_planned' => $quantityPlanned,
            'is_replaced' => false,
        ]);
    }

    /** Old payload shape (no `finding` key at all) must keep behaving identically — no Finding created. */
    public function test_completion_without_finding_payload_is_unchanged(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Kondisi masih baik.',
        ]]);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertSame(0, Finding::count());
    }

    /** Finding can be recorded when is_replaced = false. */
    public function test_finding_recorded_when_not_replaced(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Belum perlu diganti.',
            'finding' => [
                'description' => 'Bearing mulai terdengar kasar.',
                'result' => 'monitor',
                'severity' => 'medium',
                'recommendation' => 'Periksa kembali pada maintenance berikutnya.',
            ],
        ]]);

        $this->assertSame(1, Finding::count());
        $finding = Finding::first();
        $this->assertSame(FindingResult::Monitor, $finding->result);
        $this->assertSame(FindingSeverity::Medium, $finding->severity);
        $this->assertSame('Periksa kembali pada maintenance berikutnya.', $finding->recommendation);
        $this->assertFalse($check->fresh()->is_replaced);
    }

    /**
     * Finding can ALSO be recorded when is_replaced = true — Finding is not
     * a synonym for "not replaced". Normal replacement (stock/remove/
     * install) still happens exactly as before.
     */
    public function test_finding_recorded_alongside_a_real_replacement(): void
    {
        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $oldUnit = app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $oldUnit->id,
            'finding' => [
                'description' => 'Bearing failed, suara sangat kasar.',
                'result' => 'fail',
                'severity' => 'critical',
            ],
        ]]);

        $this->assertNotNull($oldUnit->fresh()->removed_at);
        $this->assertSame(1, PartInstallation::whereNull('removed_at')->count());
        $this->assertSame(1, Finding::count());
        $this->assertTrue($check->fresh()->is_replaced);
        $this->assertSame(FindingResult::Fail, Finding::first()->result);
    }

    /** Finding identity is always server-derived, never taken from the client. */
    public function test_finding_identity_is_always_server_derived(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);
        $otherUser = User::factory()->create();
        $otherEquipment = Equipment::factory()->create(['machine_id' => Machine::factory()->create(['line_id' => ProductionLine::factory()->create(['branch_id' => $this->branch->id])->id])->id]);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Observasi rutin.',
            'finding' => [
                'description' => 'Normal.',
                'result' => 'pass',
                // Attempted injection — none of these are validated fields,
                // and even if a client somehow sent them, they must never
                // reach Finding::create().
                'equipment_id' => $otherEquipment->id,
                'part_id' => 999999,
                'source_id' => 999999,
                'reported_by' => $otherUser->id,
            ],
        ]]);

        $finding = Finding::first();
        $this->assertSame($this->equipment->id, $finding->equipment_id);
        $this->assertSame($this->part->id, $finding->part_id);
        $this->assertSame($check->id, $finding->source_id);
        $this->assertSame($this->user->id, $finding->reported_by);
        $this->assertSame(FindingSourceType::TaskPartCheck, $finding->source_type);
    }

    /** severity is required unless result = pass. */
    public function test_severity_required_unless_result_is_pass(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Perlu dipantau.',
                'finding' => [
                    'description' => 'Ada getaran ringan.',
                    'result' => 'monitor',
                    // severity omitted on purpose
                ],
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('checks.0.finding.severity');
        $this->assertSame(0, Finding::count());
    }

    /**
     * Regression: the real Workspace payload for a plain checklist
     * replacement never sends a `finding` key at all (Finding is opt-in
     * UI). `required_unless:checks.*.finding.result,pass` used to treat a
     * wholly-absent `finding` the same as a present-but-non-"pass" one,
     * so severity became required even though no Finding was ever being
     * submitted — this must complete over HTTP exactly like the
     * TaskService-level test_completion_without_finding_payload_is_unchanged
     * above, no 422, no Finding created.
     */
    public function test_completion_without_finding_key_succeeds_over_http(): void
    {
        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $oldUnit = app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $oldUnit->id,
            ]],
        ]);

        $response->assertOk();
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertTrue($check->fresh()->is_replaced);
        $this->assertNotNull($oldUnit->fresh()->removed_at);
        $this->assertSame(1, PartInstallation::whereNull('removed_at')->count());
        $this->assertSame(0, Finding::count());
    }

    /** severity present alongside a non-pass result is accepted (the happy path `required` is guarding). */
    public function test_severity_accepted_when_result_is_monitor(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Perlu dipantau.',
                'finding' => [
                    'description' => 'Ada getaran ringan.',
                    'result' => 'monitor',
                    'severity' => 'low',
                ],
            ]],
        ]);

        $response->assertOk();
        $this->assertSame(1, Finding::count());
        $this->assertSame(FindingSeverity::Low, Finding::first()->severity);
    }

    /** severity is NOT required when result = pass. */
    public function test_severity_not_required_when_result_is_pass(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Kondisi normal.',
                'finding' => [
                    'description' => 'Semua terlihat baik.',
                    'result' => 'pass',
                ],
            ]],
        ]);

        $response->assertOk();
        $this->assertSame(1, Finding::count());
        $this->assertNull(Finding::first()->severity);
    }

    /** description is required whenever a finding object is submitted at all. */
    public function test_description_required_when_finding_object_present(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Perlu dipantau.',
                'finding' => [
                    'result' => 'monitor',
                    'severity' => 'low',
                ],
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('checks.0.finding.description');
        $this->assertSame(0, Finding::count());
    }

    /** An invalid result value is rejected before reaching the service (no ValueError/500). */
    public function test_invalid_result_value_is_rejected_by_validation(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        $response = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Perlu dipantau.',
                'finding' => [
                    'description' => 'Observasi.',
                    'result' => 'not_a_real_result',
                ],
            ]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('checks.0.finding.result');
        $this->assertSame(0, Finding::count());
    }

    /**
     * TaskPartCheck::finding() must never match a Finding that merely shares
     * the same source_id by coincidence but belongs to a different
     * source_type — proves the relation's source_type guard is load-bearing,
     * not decorative (source_id alone is just an integer, not unique across
     * the two possible source tables).
     */
    public function test_task_part_check_finding_relation_ignores_a_same_id_finding_from_a_different_source_type(): void
    {
        $task = $this->makeTask();
        $check = $this->makeCheck($task);

        Finding::create([
            'source_type' => FindingSourceType::PartReplacementRequest,
            'source_id' => $check->id,
            'equipment_id' => $this->equipment->id,
            'description' => 'Decoy — must never be matched by TaskPartCheck::finding().',
            'result' => FindingResult::Fail,
            'severity' => FindingSeverity::Critical,
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ]);

        $this->assertNull($check->fresh()->finding);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Observasi.',
            'finding' => [
                'description' => 'Finding asli milik checklist ini.',
                'result' => 'monitor',
                'severity' => 'low',
            ],
        ]]);

        $realFinding = $check->fresh()->finding;
        $this->assertNotNull($realFinding);
        $this->assertSame(FindingSourceType::TaskPartCheck, $realFinding->source_type);
        $this->assertSame('Finding asli milik checklist ini.', $realFinding->description);
    }

    /** Transaction integrity: if any check in the batch fails, a Finding already "created" earlier in the same batch is rolled back too. */
    public function test_finding_is_rolled_back_when_a_later_check_in_the_same_batch_fails(): void
    {
        $task = $this->makeTask();
        $checkA = $this->makeCheck($task);
        $otherPart = Part::factory()->create();
        $checkB = $this->makeCheck($task, $otherPart);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [
                [
                    'id' => $checkA->id,
                    'part_id' => $this->part->id,
                    'is_replaced' => false,
                    'reason' => 'Observasi.',
                    'finding' => [
                        'description' => 'Ini seharusnya ikut rollback.',
                        'result' => 'monitor',
                        'severity' => 'low',
                    ],
                ],
                [
                    'id' => $checkB->id,
                    'part_id' => $otherPart->id,
                    'is_replaced' => true,
                    'quantity_used' => 1,
                    'old_installation_id' => 999999, // invalid on purpose
                ],
            ]);
            $this->fail('Expected InvalidOldInstallationException was not thrown.');
        } catch (InvalidOldInstallationException) {
            // expected
        }

        $this->assertSame(0, Finding::count());
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertFalse($checkA->fresh()->is_replaced);
        $this->assertSame(0, StockLedger::count());
    }
}
