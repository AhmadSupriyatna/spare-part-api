<?php

namespace Tests\Feature;

use App\Enums\TaskLibraryMaintenanceCategory;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B Step 3 — Task Library gets a maintenance_category field
 * (LifeTime/ScheduledMaintenance/Inspection), deliberately separate from
 * schedule_type (how/when) and from PartReplacementStrategy (the Part's own
 * policy — untouched here). No cross-field compatibility rule is enforced
 * against schedule_type; any category may pair with any schedule_type,
 * including none, since the business model doesn't clearly require
 * otherwise (see StoreTaskLibraryRequest's docblock).
 */
class TaskLibraryMaintenanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCategoryProvider(): array
    {
        return [
            'life_time' => [TaskLibraryMaintenanceCategory::LifeTime->value],
            'scheduled_maintenance' => [TaskLibraryMaintenanceCategory::ScheduledMaintenance->value],
            'inspection' => [TaskLibraryMaintenanceCategory::Inspection->value],
        ];
    }

    #[DataProvider('validCategoryProvider')]
    public function test_creating_a_task_library_with_a_valid_category_succeeds(string $category): void
    {
        $response = $this->actingAs($this->engineer)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            ['title' => 'Kegiatan PM', 'maintenance_category' => $category],
        );

        $response->assertCreated();
        $this->assertSame($category, $response->json('data.maintenance_category'));
    }

    public function test_creating_a_task_library_without_category_is_rejected(): void
    {
        $response = $this->actingAs($this->engineer)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            ['title' => 'Kegiatan PM'],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('maintenance_category');
        $this->assertSame(0, TaskLibrary::count());
    }

    public function test_creating_a_task_library_with_an_invalid_category_is_rejected(): void
    {
        $response = $this->actingAs($this->engineer)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            ['title' => 'Kegiatan PM', 'maintenance_category' => 'condition_based'],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('maintenance_category');
    }

    /** A category paired with a calendar schedule is accepted — no cross-field restriction. */
    public function test_inspection_category_may_be_paired_with_a_calendar_schedule(): void
    {
        $response = $this->actingAs($this->engineer)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            [
                'title' => 'Inspeksi Bulanan',
                'maintenance_category' => TaskLibraryMaintenanceCategory::Inspection->value,
                'schedule_type' => 'calendar',
                'interval_days' => 30,
            ],
        );

        $response->assertCreated();
    }

    /** A category with no schedule at all (manual/on-demand trigger) is accepted. */
    public function test_life_time_category_may_have_no_schedule_yet(): void
    {
        $response = $this->actingAs($this->engineer)->postJson(
            "/api/equipment/{$this->equipment->id}/task-libraries",
            ['title' => 'Ganti Bearing (Umur Pakai)', 'maintenance_category' => TaskLibraryMaintenanceCategory::LifeTime->value],
        );

        $response->assertCreated();
        $this->assertNull($response->json('data.schedule_type'));
    }

    public function test_updating_the_category_of_an_existing_library_succeeds(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-01',
            'title' => 'Legacy Recipe',
        ]);
        $this->assertNull($library->maintenance_category);

        $response = $this->actingAs($this->engineer)->putJson(
            "/api/task-libraries/{$library->id}",
            ['maintenance_category' => TaskLibraryMaintenanceCategory::ScheduledMaintenance->value],
        );

        $response->assertOk();
        $this->assertSame('scheduled_maintenance', $response->json('data.maintenance_category'));
        $this->assertSame(TaskLibraryMaintenanceCategory::ScheduledMaintenance, $library->fresh()->maintenance_category);
    }

    /** Editing an unrelated field must not force a category choice — 'sometimes', not always 'required'. */
    public function test_updating_an_unrelated_field_does_not_require_category(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-02',
            'title' => 'Legacy Recipe',
        ]);

        $response = $this->actingAs($this->engineer)->putJson(
            "/api/task-libraries/{$library->id}",
            ['title' => 'Legacy Recipe (renamed)'],
        );

        $response->assertOk();
        $this->assertNull($library->fresh()->maintenance_category);
    }

    public function test_sending_an_empty_category_on_update_is_rejected(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-03',
            'title' => 'Legacy Recipe',
            'maintenance_category' => TaskLibraryMaintenanceCategory::Inspection,
        ]);

        $response = $this->actingAs($this->engineer)->putJson(
            "/api/task-libraries/{$library->id}",
            ['maintenance_category' => null],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('maintenance_category');
        $this->assertSame(TaskLibraryMaintenanceCategory::Inspection, $library->fresh()->maintenance_category);
    }

    /** category is returned by both the show and equipment-index endpoints. */
    public function test_category_is_returned_by_show_and_index(): void
    {
        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-04',
            'title' => 'Recipe',
            'maintenance_category' => TaskLibraryMaintenanceCategory::LifeTime,
        ]);

        $this->actingAs($this->engineer)
            ->getJson("/api/task-libraries/{$library->id}")
            ->assertOk()
            ->assertJsonPath('data.maintenance_category', 'life_time');

        $this->actingAs($this->engineer)
            ->getJson("/api/equipment/{$this->equipment->id}/task-libraries")
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_category', 'life_time');
    }

    /**
     * No regression: scheduling a library into a Task, and the checklist
     * quantity_required -> N TaskPartCheck rows behavior, are both
     * completely unaffected by maintenance_category (including when it's
     * still NULL on a legacy row).
     */
    public function test_scheduling_and_quantity_behavior_are_unaffected_by_category(): void
    {
        $part = Part::factory()->create();
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->engineer);

        $library = TaskLibrary::create([
            'equipment_id' => $this->equipment->id,
            'code' => 'TL-TEST-05',
            'title' => 'Legacy Recipe (no category)',
        ]);
        $library->parts()->create(['part_id' => $part->id, 'quantity_required' => 3]);

        $response = $this->actingAs($this->engineer)->postJson(
            "/api/task-libraries/{$library->id}/schedule",
            ['due_date' => now()->addWeek()->toDateString()],
        );

        // The controller returns a bare TaskResource wrapping a freshly
        // created Task — Laravel's JsonResource auto-responds 201 (not 200)
        // whenever the underlying model wasRecentlyCreated.
        $response->assertCreated();
        $task = Task::findOrFail($response->json('data.id'));
        $this->assertSame($library->id, $task->task_library_id);
        $this->assertSame(3, $task->partChecks()->count());
        foreach ($task->partChecks as $check) {
            $this->assertSame(1, $check->quantity_planned);
            $this->assertSame($part->id, $check->part_id);
        }
    }
}
