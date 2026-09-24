<?php

namespace Tests\Feature;

use App\Enums\FindingResult;
use App\Enums\FindingSourceType;
use App\Enums\PartRepairDisposition;
use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOldInstallationException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MaintenanceDecision;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\MaintenanceDecisionService;
use App\Services\MaintenanceHistoryService;
use App\Services\PartLifecycleService;
use App\Services\PartReplacementService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2, Step 5 — proves MaintenanceHistoryService's read-model surfaces
 * every existing source correctly, with no write side effects and no
 * accidental duplication of a single physical event into several rows.
 */
class MaintenanceHistoryTest extends TestCase
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

        Role::findOrCreate(UserRole::Supervisor->value, 'web');
        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::Supervisor->value);
        $this->user->branches()->attach($this->branch->id);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function history(array $query = [], ?Branch $branch = null): TestResponse
    {
        $branch ??= $this->branch;
        $url = "/api/branches/{$branch->id}/maintenance-history";
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->actingAs($this->user)->getJson($url);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        return $response->json('data');
    }

    private function installActiveUnit(?Equipment $equipment = null, ?Part $part = null): PartInstallation
    {
        return app(PartLifecycleService::class)->install(
            $equipment ?? $this->equipment,
            ['part_id' => ($part ?? $this->part)->id],
            $this->user
        );
    }

    /**
     * A Pending Task with the given planned checklist rows, not yet
     * completed — mirrors PmSchedulingService::schedule()'s own shape (one
     * TaskPartCheck row per planned unit, quantity_planned always 1 for a
     * checklist row).
     *
     * @param  list<Part>  $parts
     */
    private function makePendingTaskWithChecks(array $parts): Task
    {
        $task = Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Pending]);

        foreach ($parts as $part) {
            TaskPartCheck::create([
                'task_id' => $task->id,
                'part_id' => $part->id,
                'quantity_planned' => 1,
            ]);
        }

        return $task->fresh('partChecks');
    }

    private function pmRowFor(Task $task): array
    {
        $row = collect($this->rows($this->history()))->firstWhere('id', "task:{$task->id}");
        $this->assertNotNull($row, "No PM history row found for task #{$task->id}.");

        return $row;
    }

    private function ensureStock(Part $part): void
    {
        if (! PartStock::where('part_id', $part->id)->where('branch_id', $this->branch->id)->exists()) {
            PartStock::factory()->create(['part_id' => $part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFinding(array $overrides = []): Finding
    {
        $equipment = $overrides['equipment'] ?? $this->equipment;
        $part = $overrides['part'] ?? $this->part;
        unset($overrides['equipment'], $overrides['part']);

        $task = Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Completed]);
        $check = TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $part->id,
            'quantity_planned' => 1,
        ]);

        return Finding::create(array_merge([
            'source_type' => FindingSourceType::TaskPartCheck,
            'source_id' => $check->id,
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'description' => 'Bearing mulai terdengar kasar.',
            'result' => FindingResult::Monitor,
            'reported_by' => $this->user->id,
            'reported_at' => now(),
        ], $overrides));
    }

    // ---- A ----

    public function test_completed_pm_task_appears(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
            'title' => 'Inspeksi Rutin',
        ]);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'PM');
        $this->assertNotNull($row);
        $this->assertSame('Inspeksi Rutin', $row['title']);
    }

    // ---- B / C / D ----

    public function test_failure_request_appears_as_failure(): void
    {
        $this->installActiveUnit();
        PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'event_type' => ReplacementRequestEventType::Failure,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'FAILURE');
        $this->assertNotNull($row);
    }

    public function test_breakdown_request_appears_as_breakdown(): void
    {
        $this->installActiveUnit();
        PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'event_type' => ReplacementRequestEventType::Breakdown,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'BREAKDOWN');
        $this->assertNotNull($row);
    }

    public function test_maintenance_replacement_request_appears_as_maintenance(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();
        app(MaintenanceDecisionService::class)->decide($finding, $this->user, ['decision' => 'replace_part']);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'MAINTENANCE');
        $this->assertNotNull($row);
        $this->assertSame(ReplacementRequestStatus::Pending->value, $row['status']);
    }

    /**
     * Step 6 gap-fix: a Finding row must identify what it was reported
     * against (PM Checklist vs Replacement Request) so the Finding & Action
     * UI can show that context — see findingSourceSummary().
     */
    public function test_finding_row_carries_task_part_check_source_context(): void
    {
        $finding = $this->makeFinding();

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'FINDING');

        $this->assertNotNull($row['source']);
        $this->assertSame('task_part_check', $row['source']['type']);
        $this->assertSame($finding->taskPartCheck->task_id, $row['source']['task_id']);
        $this->assertNotNull($row['source']['task_title']);
    }

    // ---- E / F ----

    public function test_finding_appears(): void
    {
        $this->makeFinding();

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'FINDING');
        $this->assertNotNull($row);
        $this->assertNull($row['decision']);
    }

    public function test_finding_decision_linkage_appears(): void
    {
        $finding = $this->makeFinding();
        app(MaintenanceDecisionService::class)->decide($finding, $this->user, ['decision' => 'no_action']);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'FINDING');
        $this->assertNotNull($row['decision']);
        $this->assertSame('no_action', $row['decision']['decision']);
    }

    // ---- G / H ----

    public function test_decision_resulting_task_appears_linked(): void
    {
        $finding = $this->makeFinding();
        $decision = app(MaintenanceDecisionService::class)->decide($finding, $this->user, [
            'decision' => 'monitor',
            'follow_up_title' => 'Cek ulang bearing',
            'follow_up_due_date' => now()->addWeek()->toDateString(),
        ]);

        $row = collect($this->rows($this->history()))->firstWhere('source_type', 'maintenance_decision');
        $this->assertNotNull($row);
        $this->assertSame($decision->resulting_task_id, $row['decision']['resulting_task_id']);
        $this->assertNotNull($row['decision']['resulting_task_id']);
    }

    public function test_decision_resulting_replacement_request_appears_linked(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();
        $decision = app(MaintenanceDecisionService::class)->decide($finding, $this->user, ['decision' => 'replace_part']);

        $decisionRow = collect($this->rows($this->history()))->firstWhere('source_type', 'maintenance_decision');
        $this->assertSame(
            $decision->resulting_replacement_request_id,
            $decisionRow['decision']['resulting_replacement_request_id']
        );

        // Bidirectional: the MAINTENANCE request row points back to the same Decision.
        $requestRow = collect($this->rows($this->history()))->firstWhere('source_type', 'part_replacement_request');
        $this->assertSame($decision->id, $requestRow['decision']['id']);
    }

    // ---- I / J / K / L (filters) ----

    public function test_equipment_filter_works(): void
    {
        $otherEquipment = Equipment::factory()->create([
            'machine_id' => Machine::factory()->create([
                'line_id' => ProductionLine::factory()->create(['branch_id' => $this->branch->id])->id,
            ])->id,
        ]);
        $this->makeFinding(['description' => 'Milik equipment utama']);
        $this->makeFinding(['equipment' => $otherEquipment, 'description' => 'Milik equipment lain']);

        $rows = $this->rows($this->history(['equipment_id' => $this->equipment->id, 'event_type' => 'FINDING']));

        $this->assertCount(1, $rows);
        $this->assertSame('Milik equipment utama', $rows[0]['description']);
    }

    public function test_part_filter_works(): void
    {
        $otherPart = Part::factory()->create();
        $this->makeFinding(['description' => 'Part utama']);
        $this->makeFinding(['part' => $otherPart, 'description' => 'Part lain']);

        $rows = $this->rows($this->history(['part_id' => $this->part->id, 'event_type' => 'FINDING']));

        $this->assertCount(1, $rows);
        $this->assertSame('Part utama', $rows[0]['description']);
    }

    public function test_date_filter_works(): void
    {
        $this->makeFinding(['description' => 'Lama', 'reported_at' => now()->subMonths(2)]);
        $this->makeFinding(['description' => 'Baru', 'reported_at' => now()]);

        $rows = $this->rows($this->history([
            'event_type' => 'FINDING',
            'date_from' => now()->subWeek()->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('Baru', $rows[0]['description']);
    }

    public function test_event_type_filter_works(): void
    {
        $this->makeFinding();
        Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Completed, 'completed_at' => now()]);

        $rows = $this->rows($this->history(['event_type' => 'FINDING']));

        $this->assertCount(1, $rows);
        $this->assertSame('FINDING', $rows[0]['event_type']);
    }

    // ---- M / N (branch isolation) ----

    public function test_branch_isolation_and_cross_branch_records_cannot_appear(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherLine = ProductionLine::factory()->create(['branch_id' => $otherBranch->id]);
        $otherMachine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);

        $this->makeFinding(['description' => 'Branch utama']);
        $this->makeFinding(['equipment' => $otherEquipment, 'description' => 'Branch lain']);

        $rows = $this->rows($this->history(['event_type' => 'FINDING']));

        $this->assertCount(1, $rows);
        $this->assertSame('Branch utama', $rows[0]['description']);
        $this->assertFalse(collect($rows)->contains('description', 'Branch lain'));
    }

    // ---- O (multi-unit) ----

    public function test_multi_unit_replacement_reads_items_correctly(): void
    {
        $installationA = $this->installActiveUnit();
        $installationB = $this->installActiveUnit();
        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);

        $request = PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 2,
            'event_type' => ReplacementRequestEventType::Breakdown,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);

        app(PartReplacementService::class)->approve(
            $request,
            $this->user,
            null,
            [$installationA->id, $installationB->id]
        );

        $row = collect($this->rows($this->history()))->firstWhere('source_type', 'part_replacement_request');
        $this->assertCount(2, $row['replacement']['items']);
    }

    // ---- P (repair cost) ----

    public function test_repair_cost_appears_where_applicable(): void
    {
        $installation = $this->installActiveUnit();
        $repair = app(PartLifecycleService::class)->remove($installation)->partUnit->repairs()->latest('removed_at')->first();
        app(PartLifecycleService::class)->updateDisposition($repair, PartRepairDisposition::Repaired, repairCost: '150000');

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'REPAIR');
        $this->assertNotNull($row);
        $this->assertEquals(150000, (float) $row['repair']['repair_cost']);
        $this->assertSame('repaired', $row['repair']['disposition']);
    }

    // ---- Q (no write side effects) ----

    public function test_history_query_has_no_write_side_effects(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();
        app(MaintenanceDecisionService::class)->decide($finding, $this->user, ['decision' => 'replace_part']);

        $before = [
            'tasks' => Task::count(),
            'findings' => Finding::count(),
            'decisions' => MaintenanceDecision::count(),
            'requests' => PartReplacementRequest::count(),
            'ledger' => StockLedger::count(),
            'installations' => PartInstallation::count(),
            'repairs' => PartRepair::count(),
        ];

        app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $this->history();

        $after = [
            'tasks' => Task::count(),
            'findings' => Finding::count(),
            'decisions' => MaintenanceDecision::count(),
            'requests' => PartReplacementRequest::count(),
            'ledger' => StockLedger::count(),
            'installations' => PartInstallation::count(),
            'repairs' => PartRepair::count(),
        ];

        $this->assertSame($before, $after);
    }

    // ---- Data duplication (section 22) ----

    public function test_one_decision_and_its_resulting_request_each_appear_exactly_once(): void
    {
        $this->installActiveUnit();
        $finding = $this->makeFinding();
        $decision = app(MaintenanceDecisionService::class)->decide($finding, $this->user, ['decision' => 'replace_part']);

        $rows = collect($this->rows($this->history()));

        $decisionRows = $rows->where('source_type', 'maintenance_decision')->where('source_id', $decision->id);
        $requestRows = $rows->where('source_type', 'part_replacement_request')->where('source_id', $decision->resulting_replacement_request_id);

        $this->assertCount(1, $decisionRows);
        $this->assertCount(1, $requestRows);
    }

    // ---- Phase 3 Step 2: planned-replacement History enrichment ----

    /** Test 1: single replacement. */
    public function test_planned_replacement_single_check_appears_in_pm_replacement_detail(): void
    {
        $this->ensureStock($this->part);
        $old = $this->installActiveUnit();
        $task = $this->makePendingTaskWithChecks([$this->part]);
        $check = $task->partChecks->first();

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        $row = $this->pmRowFor($task->fresh());

        $this->assertSame($this->part->id, $row['part_id']);
        $this->assertSame($this->part->name, $row['part']);
        $this->assertNotNull($row['replacement']);
        $this->assertSame(1, $row['replacement']['quantity_used']);
        $this->assertCount(1, $row['replacement']['items']);

        $item = $row['replacement']['items'][0];
        $this->assertSame($this->part->name, $item['new_part']);
        $this->assertNotNull($item['new_installation']);
        $this->assertSame(1, $item['quantity_used']);
        // Phase 3 Step 3: old_installation_id is now persisted, so the old
        // side is authoritative too, not just the new one.
        $this->assertSame($old->slot_label, $item['old_installation']);
        $this->assertSame($this->part->name, $item['old_part']);

        $check->refresh();
        $this->assertSame($old->id, $check->old_installation_id);
        $this->assertNotNull($check->part_installation_id);
        $this->assertNotSame($check->old_installation_id, $check->part_installation_id);
    }

    /** Test 2: multiple physical units of the same part — neither may be lost. */
    public function test_planned_replacement_multiple_units_same_part_are_all_represented(): void
    {
        $this->ensureStock($this->part);
        $oldA = $this->installActiveUnit();
        $oldB = $this->installActiveUnit();
        $task = $this->makePendingTaskWithChecks([$this->part, $this->part]);
        $checks = $task->partChecks;

        app(TaskService::class)->complete($task, $this->user, checks: [
            [
                'id' => $checks[0]->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $oldA->id,
            ],
            [
                'id' => $checks[1]->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $oldB->id,
            ],
        ]);

        $row = $this->pmRowFor($task->fresh());

        // Same part throughout -> the aggregate quantity is meaningful.
        $this->assertSame(2, $row['replacement']['quantity_used']);
        $this->assertCount(2, $row['replacement']['items']);

        $newInstallations = collect($row['replacement']['items'])->pluck('new_installation');
        $this->assertCount(2, $newInstallations->unique(), 'Both replaced units must be distinctly represented, not collapsed into one.');

        // Each physical unit keeps its own old -> new pairing, never
        // swapped or overwritten by the other check in the same batch.
        $checks[0]->refresh();
        $checks[1]->refresh();
        $this->assertSame($oldA->id, $checks[0]->old_installation_id);
        $this->assertSame($oldB->id, $checks[1]->old_installation_id);
        $this->assertNotSame($checks[0]->part_installation_id, $checks[1]->part_installation_id);

        $oldInstallations = collect($row['replacement']['items'])->pluck('old_installation');
        $this->assertSame([$oldA->slot_label, $oldB->slot_label], $oldInstallations->sort()->values()->all());
    }

    /** Test 3: multiple different parts in one Task must not be mixed up. */
    public function test_planned_replacement_multiple_parts_are_not_mixed_up(): void
    {
        $otherPart = Part::factory()->create();
        $this->ensureStock($this->part);
        $this->ensureStock($otherPart);
        $oldPartA = $this->installActiveUnit();
        $oldPartB = $this->installActiveUnit($this->equipment, $otherPart);
        $task = $this->makePendingTaskWithChecks([$this->part, $otherPart]);
        $checks = $task->partChecks;

        app(TaskService::class)->complete($task, $this->user, checks: [
            [
                'id' => $checks[0]->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $oldPartA->id,
            ],
            [
                'id' => $checks[1]->id,
                'part_id' => $otherPart->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $oldPartB->id,
            ],
        ]);

        $row = $this->pmRowFor($task->fresh());

        // Two distinct parts -> no single top-level part/quantity to name.
        $this->assertNull($row['part_id']);
        $this->assertNull($row['part']);
        $this->assertNull($row['replacement']['quantity_used']);

        $itemParts = collect($row['replacement']['items'])->pluck('new_part')->sort()->values()->all();
        $expected = collect([$this->part->name, $otherPart->name])->sort()->values()->all();
        $this->assertSame($expected, $itemParts);

        // Old/new part resolution must not cross-contaminate between the
        // two distinct parts in this same Task.
        $itemByNewPart = collect($row['replacement']['items'])->keyBy('new_part');
        $this->assertSame($this->part->name, $itemByNewPart[$this->part->name]['old_part']);
        $this->assertSame($otherPart->name, $itemByNewPart[$otherPart->name]['old_part']);
    }

    /** Old/new part resolution reads the correct relation on each side. */
    public function test_old_and_new_part_resolve_correctly(): void
    {
        $this->ensureStock($this->part);
        $old = $this->installActiveUnit();
        $task = $this->makePendingTaskWithChecks([$this->part]);
        $check = $task->partChecks->first();

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        $check->refresh();
        $this->assertSame($this->part->id, $check->oldInstallation->part_id);
        $this->assertSame($this->part->id, $check->partInstallation->part_id);
        $this->assertNotSame($check->oldInstallation->id, $check->partInstallation->id);
    }

    /** Test 5: a rollback mid-batch must not leave a partial old_installation_id anywhere. */
    public function test_transaction_rollback_leaves_no_partial_old_installation_id(): void
    {
        $this->ensureStock($this->part);
        $old = $this->installActiveUnit();
        $task = $this->makePendingTaskWithChecks([$this->part]);
        $checkA = $task->partChecks->first();
        $otherPart = Part::factory()->create();
        TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $otherPart->id, 'quantity_planned' => 1]);
        $checkB = $task->fresh('partChecks')->partChecks->last();

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [
                [
                    'id' => $checkA->id,
                    'part_id' => $this->part->id,
                    'is_replaced' => true,
                    'quantity_used' => 1,
                    'old_installation_id' => $old->id,
                ],
                [
                    'id' => $checkB->id,
                    'part_id' => $otherPart->id,
                    'is_replaced' => true,
                    'quantity_used' => 1,
                    'old_installation_id' => 999999, // invalid on purpose -> throws mid-batch
                ],
            ]);
            $this->fail('Expected an exception for the invalid old_installation_id.');
        } catch (InvalidOldInstallationException) {
            // expected
        }

        $checkA->refresh();
        $this->assertNull($checkA->old_installation_id, 'The first check must not keep a partial old_installation_id once the batch rolls back.');
        $this->assertNull($checkA->part_installation_id);
        // Untouched, still at its pre-completion default — the second loop
        // (which would set is_replaced) never even started.
        $this->assertNull($checkA->is_replaced);
        $this->assertNotNull($old->fresh()->id);
        $this->assertNull($old->fresh()->removed_at, 'The old installation must not have been removed either — the whole batch rolled back.');
    }

    /** Test 7 (part 1): historical rows with no persisted old installation stay safely null, never guessed. */
    public function test_history_stays_safe_for_a_historical_row_without_persisted_old_installation(): void
    {
        // Simulates a row completed before the old_installation_id column
        // existed: part_installation_id (new) is set, old_installation_id
        // is not — exactly as a pre-migration historical record would be.
        $task = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]);
        $newInstallation = $this->installActiveUnit();
        TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => 1,
            'is_replaced' => true,
            'quantity_used' => 1,
            'part_installation_id' => $newInstallation->id,
            'old_installation_id' => null,
        ]);

        $row = $this->pmRowFor($task);

        $this->assertNotNull($row['replacement']);
        $item = $row['replacement']['items'][0];
        $this->assertNull($item['old_installation']);
        $this->assertNull($item['old_part']);
        $this->assertSame($this->part->name, $item['new_part']);
        $this->assertNotNull($item['new_installation']);
    }

    /** Test 4: a completed Task with no replacement must not show fake replacement detail. */
    public function test_completed_task_without_replacement_has_no_replacement_detail(): void
    {
        $task = $this->makePendingTaskWithChecks([$this->part]);
        $check = $task->partChecks->first();

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Kondisi masih baik.',
        ]]);

        $row = $this->pmRowFor($task->fresh());

        $this->assertNull($row['replacement']);
    }

    /** Test 5: enrichment must not disturb the existing Failure/Breakdown/Maintenance replacement shape. */
    public function test_failure_breakdown_maintenance_replacement_detail_unaffected_by_pm_enrichment(): void
    {
        $this->installActiveUnit();
        PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => 1,
            'event_type' => ReplacementRequestEventType::Failure,
            'requested_by_name' => 'Teknisi UAT',
            'status' => ReplacementRequestStatus::Pending,
        ]);

        $row = collect($this->rows($this->history()))->firstWhere('event_type', 'FAILURE');

        $this->assertSame(1, $row['replacement']['quantity_used']);
        $this->assertSame([], $row['replacement']['items']); // not yet approved -> no items
    }

    /** Test 6: existing filters keep working once a PM row carries replacement detail. */
    public function test_filters_still_work_with_enriched_pm_rows(): void
    {
        $otherPart = Part::factory()->create();
        $this->ensureStock($this->part);
        $old = $this->installActiveUnit();
        $task = $this->makePendingTaskWithChecks([$this->part]);
        $check = $task->partChecks->first();

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        $byPart = $this->rows($this->history(['part_id' => $this->part->id, 'event_type' => 'PM']));
        $this->assertCount(1, $byPart);

        $byOtherPart = $this->rows($this->history(['part_id' => $otherPart->id, 'event_type' => 'PM']));
        $this->assertCount(0, $byOtherPart);

        $byEquipment = $this->rows($this->history(['equipment_id' => $this->equipment->id, 'event_type' => 'PM']));
        $this->assertCount(1, $byEquipment);
    }
}
