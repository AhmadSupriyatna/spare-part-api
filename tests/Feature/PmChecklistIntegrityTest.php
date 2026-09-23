<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\ChecklistPartMismatchException;
use App\Exceptions\InvalidOldInstallationException;
use App\Exceptions\PartStockBranchMismatchException;
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
use App\Services\PartLifecycleService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final Phase 1 hardening: PM/Task data integrity. Three gaps found in
 * audit, all in TaskService::complete()/completeChecklist():
 * 1. checks.*.part_id was never checked against the actual TaskPartCheck
 *    row it claims to update — a client could swap which part a checklist
 *    row acted on.
 * 2. old_installation_id (shared with breakdown via
 *    PartLifecycleService::resolveActiveInstallation()) was trusted at face
 *    value with no ownership check.
 * 3. part_stock_id/quantity_used on a plain (non-checklist) task was never
 *    checked against the task equipment's own branch before issuing stock.
 */
class PmChecklistIntegrityTest extends TestCase
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
        $this->user = User::factory()->create();
    }

    private function makeTask(?Equipment $equipment = null): Task
    {
        return Task::factory()->create([
            'equipment_id' => ($equipment ?? $this->equipment)->id,
            'status' => TaskStatus::Pending,
        ]);
    }

    private function makeCheck(Task $task, Part $part, int $quantityPlanned = 1): TaskPartCheck
    {
        return TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $part->id,
            'quantity_planned' => $quantityPlanned,
            'is_replaced' => false,
        ]);
    }

    private function installUnit(?Equipment $equipment = null, ?Part $part = null): PartInstallation
    {
        return app(PartLifecycleService::class)->install(
            $equipment ?? $this->equipment,
            ['part_id' => ($part ?? $this->part)->id],
            $this->user,
        );
    }

    // --- A. Checklist part mismatch ---

    public function test_a1_matching_part_id_passes(): void
    {
        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
        ]]);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertTrue($check->fresh()->is_replaced);
    }

    public function test_a2_mismatched_part_id_is_rejected(): void
    {
        $otherPart = Part::factory()->create();
        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $otherPart->id, // spoofed: check is actually for $this->part
                'is_replaced' => true,
                'quantity_used' => 1,
            ]]);
            $this->fail('Expected ChecklistPartMismatchException was not thrown.');
        } catch (ChecklistPartMismatchException) {
            // expected
        }

        // A3: rejection must not touch task/checklist/stock/installations.
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertFalse($check->fresh()->is_replaced);
        $this->assertNull($check->fresh()->part_installation_id);
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, PartInstallation::count());
    }

    // --- B. PM old installation ---

    public function test_b1_valid_installation_on_correct_equipment_and_part_passes(): void
    {
        PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $unit = $this->installUnit();
        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
            'old_installation_id' => $unit->id,
        ]]);

        $this->assertNotNull($unit->fresh()->removed_at);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_b2_installation_from_a_different_equipment_is_rejected(): void
    {
        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 100]);
        $otherMachine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        $foreignInstallation = $this->installUnit($otherEquipment, $this->part);

        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $foreignInstallation->id,
            ]]);
            $this->fail('Expected InvalidOldInstallationException was not thrown.');
        } catch (InvalidOldInstallationException) {
            // expected
        }

        $this->assertNull($foreignInstallation->fresh()->removed_at);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertFalse($check->fresh()->is_replaced);
    }

    public function test_b3_installation_of_a_different_part_is_rejected(): void
    {
        $otherPart = Part::factory()->create();
        $otherPartInstallation = $this->installUnit($this->equipment, $otherPart);

        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $otherPartInstallation->id,
            ]]);
            $this->fail('Expected InvalidOldInstallationException was not thrown.');
        } catch (InvalidOldInstallationException) {
            // expected
        }

        $this->assertNull($otherPartInstallation->fresh()->removed_at);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_b4_already_removed_installation_is_rejected(): void
    {
        $unit = $this->installUnit();
        app(PartLifecycleService::class)->remove($unit);

        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        try {
            app(TaskService::class)->complete($task, $this->user, checks: [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => true,
                'quantity_used' => 1,
                'old_installation_id' => $unit->id,
            ]]);
            $this->fail('Expected InvalidOldInstallationException was not thrown.');
        } catch (InvalidOldInstallationException) {
            // expected
        }

        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
    }

    public function test_b5_existing_ambiguous_behaviour_without_explicit_pick_is_unchanged(): void
    {
        $this->installUnit();
        $this->installUnit();

        $task = $this->makeTask();
        $check = $this->makeCheck($task, $this->part);

        $this->expectException(AmbiguousInstallationException::class);
        app(TaskService::class)->complete($task, $this->user, checks: [[
            'id' => $check->id,
            'part_id' => $this->part->id,
            'is_replaced' => true,
            'quantity_used' => 1,
        ]]);
    }

    // --- C. Task stock branch ---

    public function test_c1_part_stock_in_the_same_branch_as_task_equipment_passes(): void
    {
        $stock = PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $this->branch->id, 'quantity_on_hand' => 10]);
        $task = $this->makeTask();

        app(TaskService::class)->complete($task, $this->user, partStockId: $stock->id, quantityUsed: 2);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertSame(8, $stock->fresh()->quantity_on_hand);
        $this->assertSame(1, StockLedger::where('part_stock_id', $stock->id)->count());
    }

    public function test_c2_part_stock_from_a_different_branch_is_rejected(): void
    {
        $otherBranch = Branch::factory()->create();
        $stock = PartStock::factory()->create(['part_id' => $this->part->id, 'branch_id' => $otherBranch->id, 'quantity_on_hand' => 10]);
        $task = $this->makeTask();

        try {
            app(TaskService::class)->complete($task, $this->user, partStockId: $stock->id, quantityUsed: 2);
            $this->fail('Expected PartStockBranchMismatchException was not thrown.');
        } catch (PartStockBranchMismatchException) {
            // expected
        }

        // C3 + C4: no ledger, task not completed.
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);
        $this->assertSame(10, $stock->fresh()->quantity_on_hand);
    }
}
