<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Exceptions\PartStockNotFoundException;
use App\Exceptions\TaskAlreadyCompletedException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Services\MaintenanceHistoryService;
use App\Services\PartLifecycleService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3, Step 4 — end-to-end hardening for the planned-replacement flow
 * (Task Library / Part Lifetime -> Task -> checklist completion -> Part
 * Lifecycle). No new orchestration, no idempotency framework — this proves
 * what the existing TaskService::completeChecklist() actually does, and
 * documents (rather than silently patches) whatever it doesn't.
 */
class PlannedReplacementHardeningTest extends TestCase
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

    private function ensureStock(Part $part, int $quantity = 10): PartStock
    {
        return PartStock::firstOrCreate(
            ['part_id' => $part->id, 'branch_id' => $this->branch->id],
            ['quantity_on_hand' => $quantity]
        );
    }

    private function installActiveUnit(?Part $part = null): PartInstallation
    {
        return app(PartLifecycleService::class)->install(
            $this->equipment,
            ['part_id' => ($part ?? $this->part)->id],
            $this->user
        );
    }

    private function pendingTask(): Task
    {
        return Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Pending]);
    }

    private function check(Task $task, Part $part): TaskPartCheck
    {
        return TaskPartCheck::create(['task_id' => $task->id, 'part_id' => $part->id, 'quantity_planned' => 1]);
    }

    // ---- Step 2: single-unit replacement ----

    public function test_single_unit_replacement_is_fully_correct(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $check = $this->check($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        // Stock: exactly one issue of -1, nothing else.
        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);
        $this->assertSame(1, StockLedger::where('part_stock_id', $stock->id)->count());
        $this->assertSame(-1, StockLedger::where('part_stock_id', $stock->id)->value('quantity_change'));

        // Old installation: removed, no longer active.
        $old->refresh();
        $this->assertNotNull($old->removed_at);
        $this->assertSame(0, PartInstallation::where('equipment_id', $this->equipment->id)->where('part_id', $this->part->id)->whereKey($old->id)->whereNull('removed_at')->count());

        // New installation: active, distinct from the old one.
        $check->refresh();
        $new = PartInstallation::findOrFail($check->part_installation_id);
        $this->assertNull($new->removed_at);
        $this->assertNotSame($old->id, $new->id);

        // TaskPartCheck.
        $this->assertTrue($check->is_replaced);
        $this->assertSame($old->id, $check->old_installation_id);
        $this->assertSame($new->id, $check->part_installation_id);
        $this->assertSame(1, $check->quantity_used);

        // History (the actual contract the UI reads).
        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $row = $rows->firstWhere('id', "task:{$task->id}");
        $this->assertSame('PM', $row['event_type']);
        $this->assertSame(1, $row['replacement']['quantity_used']);
        $item = $row['replacement']['items'][0];
        $this->assertSame($old->slot_label, $item['old_installation']);
        $this->assertSame($this->part->name, $item['old_part']);
        $this->assertSame($new->slot_label, $item['new_installation']);
        $this->assertSame($this->part->name, $item['new_part']);
    }

    // ---- Step 3: multi-unit replacement (same part, two physical units) ----

    public function test_multi_unit_replacement_pairs_correctly_without_swapping(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $oldA = $this->installActiveUnit();
        $oldB = $this->installActiveUnit();
        $task = $this->pendingTask();
        $checkA = $this->check($task, $this->part);
        $checkB = $this->check($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [
            ['id' => $checkA->id, 'part_id' => $this->part->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $oldA->id],
            ['id' => $checkB->id, 'part_id' => $this->part->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $oldB->id],
        ]);

        // Stock: two separate -1 issues, never one combined -2 or a third movement.
        $stock->refresh();
        $this->assertSame(8, $stock->quantity_on_hand);
        $this->assertSame(2, StockLedger::where('part_stock_id', $stock->id)->count());
        $this->assertTrue(StockLedger::where('part_stock_id', $stock->id)->pluck('quantity_change')->every(fn ($c) => $c === -1));

        $checkA->refresh();
        $checkB->refresh();
        $this->assertSame($oldA->id, $checkA->old_installation_id);
        $this->assertSame($oldB->id, $checkB->old_installation_id);
        $this->assertNotSame($checkA->part_installation_id, $checkB->part_installation_id);

        $oldA->refresh();
        $oldB->refresh();
        $this->assertNotNull($oldA->removed_at);
        $this->assertNotNull($oldB->removed_at);
        $this->assertSame(2, PartInstallation::where('equipment_id', $this->equipment->id)->where('part_id', $this->part->id)->whereNull('removed_at')->count());

        // Same API surface the UI calls.
        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $row = $rows->firstWhere('id', "task:{$task->id}");
        $this->assertSame(2, $row['replacement']['quantity_used']);
        $this->assertCount(2, $row['replacement']['items']);
        // Each item's own old/new share the same slot_label by design
        // (PartLifecycleService::resolveSlotLabel() deliberately inherits
        // it — "address continuity" for the same physical position), so the
        // real no-swap proof is that the PAIR of (old, new) for check A is
        // distinct from the pair for check B, not that old != new within
        // one pair.
        $pairs = collect($row['replacement']['items'])->map(fn ($i) => $i['old_installation'].'->'.$i['new_installation']);
        $this->assertCount(2, $pairs->unique(), 'Each physical unit must keep its own distinct old->new pair, not share one with the other.');
    }

    // ---- Step 4: multi-part Task ----

    public function test_multi_part_task_keeps_parts_and_quantities_separate(): void
    {
        $bearing = $this->part;
        $seal = Part::factory()->create();
        $this->ensureStock($bearing, 10);
        $this->ensureStock($seal, 10);

        $oldBearingA = $this->installActiveUnit($bearing);
        $oldBearingB = $this->installActiveUnit($bearing);
        $oldSeal = $this->installActiveUnit($seal);

        $task = $this->pendingTask();
        $checkBearingA = $this->check($task, $bearing);
        $checkBearingB = $this->check($task, $bearing);
        $checkSeal = $this->check($task, $seal);

        app(TaskService::class)->complete($task, $this->user, checks: [
            ['id' => $checkBearingA->id, 'part_id' => $bearing->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $oldBearingA->id],
            ['id' => $checkBearingB->id, 'part_id' => $bearing->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $oldBearingB->id],
            ['id' => $checkSeal->id, 'part_id' => $seal->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $oldSeal->id],
        ]);

        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $row = $rows->firstWhere('id', "task:{$task->id}");

        // Two distinct parts involved -> no single top-level part/quantity.
        $this->assertNull($row['part_id']);
        $this->assertNull($row['part']);
        $this->assertNull($row['replacement']['quantity_used']);
        $this->assertCount(3, $row['replacement']['items']);

        $bySlot = collect($row['replacement']['items'])->keyBy('new_installation');
        // Bearing items must say "bearing" on both sides, seal item "seal" on both sides — never crossed.
        foreach ($row['replacement']['items'] as $item) {
            if ($item['old_part'] === $bearing->name) {
                $this->assertSame($bearing->name, $item['new_part']);
            } elseif ($item['old_part'] === $seal->name) {
                $this->assertSame($seal->name, $item['new_part']);
            } else {
                $this->fail('Unexpected old_part in replacement items: '.$item['old_part']);
            }
        }
        unset($bySlot);

        $checkBearingA->refresh();
        $checkBearingB->refresh();
        $checkSeal->refresh();
        $this->assertSame($bearing->id, $checkBearingA->part_id);
        $this->assertSame($bearing->id, $checkBearingB->part_id);
        $this->assertSame($seal->id, $checkSeal->part_id);
    }

    // ---- Step 5: no replacement ----

    public function test_no_replacement_task_leaves_stock_and_lifecycle_untouched(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $check = $this->check($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => false,
            'reason' => 'Kondisi masih baik.',
        ]]);

        $stock->refresh();
        $this->assertSame(10, $stock->quantity_on_hand);
        $this->assertSame(0, StockLedger::count());

        $old->refresh();
        $this->assertNull($old->removed_at);

        $check->refresh();
        $this->assertNull($check->old_installation_id);
        $this->assertNull($check->part_installation_id);

        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $row = $rows->firstWhere('id', "task:{$task->id}");
        $this->assertNull($row['replacement']);
    }

    // ---- Phase 3 Step 4A: duplicate completion is rejected, state unchanged ----

    /**
     * TaskService::complete() now locks the Task row and rejects a second
     * completion attempt (TaskAlreadyCompletedException) before any
     * stock/lifecycle mutation runs — see Step 4's finding and Step 4A's
     * fix. This replaces the old test that documented the bug.
     */
    public function test_duplicate_completion_is_rejected_and_leaves_state_unchanged(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $check = $this->check($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);
        $checkAfterFirst = $check->fresh();
        $firstNewInstallationId = $checkAfterFirst->part_installation_id;
        $firstOldInstallationId = $checkAfterFirst->old_installation_id;

        // Second call: same Task (already Completed), same check, no
        // explicit old_installation_id — auto-resolve would otherwise pick
        // whatever is currently active (the installation the first call
        // just created). Must be rejected before that resolution even runs.
        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
            ]]);
            $this->fail('Expected TaskAlreadyCompletedException on the second completion.');
        } catch (TaskAlreadyCompletedException) {
            // expected
        }

        // Nothing changed: no second stock issue, no second install/remove,
        // TaskPartCheck's old/new references are exactly what the first
        // completion left them at, Task stays Completed (not re-touched).
        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);
        $this->assertSame(1, StockLedger::where('part_stock_id', $stock->id)->count());

        $check->refresh();
        $this->assertSame($firstNewInstallationId, $check->part_installation_id);
        $this->assertSame($firstOldInstallationId, $check->old_installation_id);

        $firstInstallation = PartInstallation::findOrFail($firstNewInstallationId);
        $this->assertNull($firstInstallation->removed_at, 'The first completion\'s new installation must remain active.');
        $this->assertSame(2, PartInstallation::where('equipment_id', $this->equipment->id)->where('part_id', $this->part->id)->count(), 'No third installation should ever exist.');

        // History must not gain a second replacement event for this Task.
        $rows = app(MaintenanceHistoryService::class)->forBranch($this->branch);
        $pmRowsForTask = $rows->where('id', "task:{$task->id}");
        $this->assertCount(1, $pmRowsForTask);
        $this->assertCount(1, $pmRowsForTask->first()['replacement']['items']);
    }

    /**
     * Same as above, but the second attempt explicitly sends the ORIGINAL
     * old_installation_id — the guard must reject before that ID is even
     * looked at, not merely when the caller omits it.
     */
    public function test_duplicate_completion_with_explicit_old_installation_id_is_also_rejected(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $check = $this->check($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $old->id,
        ]]);

        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $old->id, // the FIRST call's old installation, already inactive
            ]]);
            $this->fail('Expected TaskAlreadyCompletedException even with an explicit old_installation_id.');
        } catch (TaskAlreadyCompletedException) {
            // expected
        }

        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);
        $this->assertSame(1, StockLedger::count());
        $old->refresh();
        $this->assertNotNull($old->removed_at); // untouched since the first completion — still removed, not re-processed
    }

    /**
     * The same guard, exercised through the real HTTP endpoint (not the
     * service directly) — first call 200, second call a non-2xx (409), and
     * the database state after the rejected second call is identical to
     * the state right after the first call.
     */
    public function test_http_duplicate_completion_is_rejected(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $check = $this->check($task, $this->part);

        $payload = [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $old->id,
                'finding' => ['description' => 'Kondisi baik.', 'result' => 'pass'],
            ]],
        ];

        $first = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", $payload);
        $first->assertOk();

        $stock->refresh();
        $this->assertSame(9, $stock->quantity_on_hand);
        $stateAfterFirst = [
            'quantity_on_hand' => $stock->quantity_on_hand,
            'ledger_count' => StockLedger::count(),
            'part_installation_id' => $check->fresh()->part_installation_id,
            'old_installation_id' => $check->fresh()->old_installation_id,
        ];

        $second = $this->actingAs($this->user)->postJson("/api/tasks/{$task->id}/complete", $payload);
        $second->assertStatus(409);

        $stock->refresh();
        $this->assertSame($stateAfterFirst['quantity_on_hand'], $stock->quantity_on_hand);
        $this->assertSame($stateAfterFirst['ledger_count'], StockLedger::count());
        $check->refresh();
        $this->assertSame($stateAfterFirst['part_installation_id'], $check->part_installation_id);
        $this->assertSame($stateAfterFirst['old_installation_id'], $check->old_installation_id);
    }

    // ---- Step 7: rollback mid-batch, after an earlier check already fully succeeded ----

    public function test_rollback_restores_everything_when_a_later_check_fails_after_an_earlier_one_succeeded(): void
    {
        $stock = $this->ensureStock($this->part, 10);
        $old = $this->installActiveUnit();
        $task = $this->pendingTask();
        $checkA = $this->check($task, $this->part);

        $partWithNoStock = Part::factory()->create(); // deliberately no PartStock row for this part/branch
        $checkB = $this->check($task, $partWithNoStock);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [
                ['id' => $checkA->id, 'part_id' => $this->part->id, 'is_replaced' => true, 'quantity_used' => 1, 'old_installation_id' => $old->id],
                ['id' => $checkB->id, 'part_id' => $partWithNoStock->id, 'is_replaced' => true, 'quantity_used' => 1],
            ]);
            $this->fail('Expected PartStockNotFoundException for the second check.');
        } catch (PartStockNotFoundException) {
            // expected — thrown mid-second-loop, AFTER check A's stock
            // issue + remove + install already ran in this same transaction.
        }

        // Stock: back to baseline, no partial issue for check A survives.
        $stock->refresh();
        $this->assertSame(10, $stock->quantity_on_hand);
        $this->assertSame(0, StockLedger::count());

        // Old installation: active again, never actually removed.
        $old->refresh();
        $this->assertNull($old->removed_at);

        // No new installation left over from check A's aborted attempt.
        $this->assertSame(1, PartInstallation::where('equipment_id', $this->equipment->id)->where('part_id', $this->part->id)->count());

        // TaskPartCheck: back to its pre-completion state.
        $checkA->refresh();
        $this->assertNull($checkA->is_replaced);
        $this->assertNull($checkA->old_installation_id);
        $this->assertNull($checkA->part_installation_id);

        // Task: never marked completed.
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }
}
