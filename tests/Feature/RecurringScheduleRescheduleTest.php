<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskLibrary;
use App\Models\User;
use App\Services\PmSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bug report: dragging a recurring (Task Library Weekly/Monthly) WO on the
 * PM Calendar to a new date left the old date still showing a WO too —
 * ensureRecurringSchedules() re-creates the current period's occurrence on
 * every page load if it finds no task sitting on that period's natural due
 * date, and a drag-to-reschedule moves the existing task's due_date AWAY
 * from that date, so the next page load saw the period as "still
 * unscheduled" and created a second task right back on it.
 */
class RecurringScheduleRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private TaskLibrary $library;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        $this->library = TaskLibrary::create([
            'equipment_id' => $equipment->id,
            'code' => 'TL-WEEKLY-01',
            'title' => 'Cek Mingguan',
            'schedule_type' => 'calendar',
            'interval_days' => 7,
            'is_active' => true,
        ]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole(UserRole::Supervisor->value);
        $this->supervisor->branches()->attach($this->branch->id);
    }

    public function test_rescheduling_a_recurring_task_does_not_leave_a_duplicate_on_its_original_date(): void
    {
        $scheduling = app(PmSchedulingService::class);

        $scheduling->ensureRecurringSchedules($this->branch);
        $this->assertSame(1, Task::where('task_library_id', $this->library->id)->count());

        $task = Task::where('task_library_id', $this->library->id)->firstOrFail();
        $originalDueDate = $task->due_date->toDateString();
        $newDueDate = Carbon::parse($originalDueDate)->addDays(2)->toDateString();

        $this->actingAs($this->supervisor)
            ->putJson("/api/tasks/{$task->id}/reschedule", ['due_date' => $newDueDate])
            ->assertOk();

        // Calling ensureRecurringSchedules() again simulates the next PM
        // Calendar page load — it must recognize this period is already
        // covered by the (now-moved) task instead of creating a second one.
        $scheduling->ensureRecurringSchedules($this->branch);

        $tasks = Task::where('task_library_id', $this->library->id)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($newDueDate, $tasks->first()->due_date->toDateString());
    }

    /** Regression guard: a genuinely new period (no task, no reschedule history for it) must still get scheduled. */
    public function test_a_period_with_no_existing_task_still_gets_scheduled(): void
    {
        $scheduling = app(PmSchedulingService::class);

        $scheduling->ensureRecurringSchedules($this->branch);
        $this->assertSame(1, Task::where('task_library_id', $this->library->id)->count());

        // Calling it again for the SAME still-current period must stay a no-op.
        $scheduling->ensureRecurringSchedules($this->branch);
        $this->assertSame(1, Task::where('task_library_id', $this->library->id)->count());
    }
}
