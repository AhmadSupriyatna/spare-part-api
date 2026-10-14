<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLibrary;
use App\Models\TaskPartCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Condition-check items (Kondisi Baik/Tidak) and their related parts on a
 * Task Library. Covers recipe creation, snapshot-onto-Task
 * (PmSchedulingService::schedule()), completion
 * (TaskService::completeChecklistItems()), and the Machine-scoped weekly
 * grouping read used by "Cetak Checklist Mingguan".
 */
class TaskChecklistItemTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Machine $machine;

    private Equipment $equipment;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $this->machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $this->machine->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
    }

    public function test_creating_a_task_library_with_bundled_checklist_items_stores_them(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/equipment/{$this->equipment->id}/task-libraries", [
            'title' => 'PM Mingguan Heat Exchanger',
            'checklist_items' => [
                ['description' => 'Bersih dari sisa produk, kotoran & debu'],
                ['description' => 'Periksa kebocoran'],
            ],
        ]);

        $response->assertCreated();
        $descriptions = collect($response->json('data.checklist_items'))->pluck('description');
        $this->assertSame(
            ['Bersih dari sisa produk, kotoran & debu', 'Periksa kebocoran'],
            $descriptions->all()
        );
    }

    public function test_adding_a_checklist_item_to_an_existing_library(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'PM Mingguan',
            'code' => 'TL-1',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/task-libraries/{$library->id}/checklist-items", [
            'description' => 'Cek fungsional Fan',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('task_library_checklist_items', [
            'task_library_id' => $library->id,
            'description' => 'Cek fungsional Fan',
        ]);
    }

    public function test_deleting_a_checklist_item(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'PM Mingguan',
            'code' => 'TL-1',
            'is_active' => true,
        ]);
        $item = $library->checklistItems()->create(['description' => 'Periksa kebocoran']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/task-library-checklist-items/{$item->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('task_library_checklist_items', ['id' => $item->id]);
    }

    public function test_scheduling_a_library_snapshots_its_checklist_items_onto_the_task(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'PM Mingguan',
            'code' => 'TL-1',
            'is_active' => true,
        ]);
        $library->checklistItems()->create(['description' => 'Periksa kebocoran', 'order' => 0]);
        $library->checklistItems()->create(['description' => 'Cek fungsional Fan', 'order' => 1]);

        $response = $this->actingAs($this->admin)->postJson("/api/task-libraries/{$library->id}/schedule", [
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated();
        $taskId = $response->json('data.id');
        $this->assertSame(2, TaskChecklistItem::where('task_id', $taskId)->count());
        $descriptions = TaskChecklistItem::where('task_id', $taskId)->pluck('description');
        $this->assertTrue($descriptions->contains('Periksa kebocoran'));
        $this->assertTrue($descriptions->contains('Cek fungsional Fan'));
    }

    public function test_library_parts_are_nested_under_a_condition_and_keep_that_link_when_scheduled(): void
    {
        $part = Part::factory()->create();
        PartInstallation::create(['equipment_id' => $this->equipment->id, 'part_id' => $part->id]);

        $libraryResponse = $this->actingAs($this->admin)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            [
                'title' => 'PM Mingguan',
                'checklist_items' => [
                    [
                        'description' => 'Periksa kebocoran',
                        'parts' => [['part_id' => $part->id]],
                    ],
                ],
            ]
        );

        $libraryResponse->assertCreated();
        $libraryChecklistItemId = $libraryResponse->json('data.checklist_items.0.id');
        $this->assertSame(
            $libraryChecklistItemId,
            $libraryResponse->json('data.checklist_items.0.parts.0.task_library_checklist_item_id')
        );

        $libraryId = $libraryResponse->json('data.id');
        $taskId = $this->actingAs($this->admin)->postJson(
            "/api/task-libraries/{$libraryId}/schedule",
            ['due_date' => now()->addWeek()->toDateString()]
        )->assertCreated()->json('data.id');

        $taskChecklistItemId = TaskChecklistItem::where('task_id', $taskId)->value('id');
        $this->assertDatabaseHas('task_part_checks', [
            'task_id' => $taskId,
            'task_checklist_item_id' => $taskChecklistItemId,
            'part_id' => $part->id,
        ]);
        $this->assertSame(
            $taskChecklistItemId,
            TaskPartCheck::where('task_id', $taskId)->value('task_checklist_item_id')
        );
    }

    public function test_completing_a_task_persists_condition_ok_and_notes_per_item(): void
    {
        $engineer = User::factory()->create();
        $engineer->assignRole(UserRole::Engineer->value);
        $engineer->branches()->attach($this->branch->id);

        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'title' => 'PM Mingguan',
            'code' => 'TL-1',
            'is_active' => true,
        ]);
        $library->checklistItems()->create(['description' => 'Periksa kebocoran', 'order' => 0]);

        $scheduleResponse = $this->actingAs($this->admin)->postJson("/api/task-libraries/{$library->id}/schedule", [
            'due_date' => now()->addWeek()->toDateString(),
        ]);
        $taskId = $scheduleResponse->json('data.id');
        $itemId = TaskChecklistItem::where('task_id', $taskId)->value('id');

        $response = $this->actingAs($engineer)->postJson("/api/tasks/{$taskId}/complete", [
            'checklist_items' => [
                ['id' => $itemId, 'condition_ok' => false, 'notes' => 'Ada rembesan kecil di seal'],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('task_checklist_items', [
            'id' => $itemId,
            'condition_ok' => false,
            'notes' => 'Ada rembesan kecil di seal',
        ]);
        $this->assertSame(TaskStatus::Completed->value, Task::find($taskId)->status->value);
    }

    public function test_wo_report_for_machine_only_returns_tasks_for_that_machine_and_date_range(): void
    {
        $otherEquipmentSameMachine = Equipment::factory()->create(['machine_id' => $this->machine->id]);
        $otherMachine = Machine::factory()->create(['line_id' => $this->machine->line_id]);
        $equipmentOnOtherMachine = Equipment::factory()->create(['machine_id' => $otherMachine->id]);

        $weekStart = now()->next('Monday')->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6);

        $inWeekSameMachine = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => $weekStart->copy()->addDays(2),
        ]);
        $inWeekOtherEquipmentSameMachine = Task::factory()->create([
            'equipment_id' => $otherEquipmentSameMachine->id,
            'due_date' => $weekStart->copy()->addDays(4),
        ]);
        $outsideWeekSameMachine = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => $weekStart->copy()->addDays(9),
        ]);
        $inWeekOtherMachine = Task::factory()->create([
            'equipment_id' => $equipmentOnOtherMachine->id,
            'due_date' => $weekStart->copy()->addDays(1),
        ]);
        $cancelledInWeek = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => $weekStart->copy()->addDays(3),
            'status' => TaskStatus::Cancelled,
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            "/api/machines/{$this->machine->id}/wo-report?from={$weekStart->toDateString()}&to={$weekEnd->toDateString()}"
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($inWeekSameMachine->id));
        $this->assertTrue($ids->contains($inWeekOtherEquipmentSameMachine->id));
        $this->assertFalse($ids->contains($outsideWeekSameMachine->id));
        $this->assertFalse($ids->contains($inWeekOtherMachine->id));
        $this->assertFalse($ids->contains($cancelledInWeek->id));
    }

    public function test_wo_report_for_line_aggregates_every_machine_under_it(): void
    {
        $otherMachineSameLine = Machine::factory()->create(['line_id' => $this->machine->line_id]);
        $equipmentOnOtherMachineSameLine = Equipment::factory()->create(['machine_id' => $otherMachineSameLine->id]);

        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machineOnOtherLine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $equipmentOnOtherLine = Equipment::factory()->create(['machine_id' => $machineOnOtherLine->id]);

        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $inRangeThisMachine = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => $from->copy()->addDays(2),
        ]);
        $inRangeOtherMachineSameLine = Task::factory()->create([
            'equipment_id' => $equipmentOnOtherMachineSameLine->id,
            'due_date' => $from->copy()->addDays(5),
        ]);
        $inRangeOtherLine = Task::factory()->create([
            'equipment_id' => $equipmentOnOtherLine->id,
            'due_date' => $from->copy()->addDays(3),
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            "/api/lines/{$this->machine->line_id}/wo-report?from={$from->toDateString()}&to={$to->toDateString()}"
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($inRangeThisMachine->id));
        $this->assertTrue($ids->contains($inRangeOtherMachineSameLine->id));
        $this->assertFalse($ids->contains($inRangeOtherLine->id));
    }
}
