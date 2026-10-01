<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two bugs found and fixed together, both the same root cause: every place
 * that resolved "which Tasks/recipes belong to this branch" only walked
 * equipment.machine.line (2 levels), so an equipment sitting under a "Mesin
 * Luar Line" (outside-line Machine — line_id null, branch_id set) matched
 * nothing and silently never showed up or auto-scheduled. Fixed by reusing
 * the same 3-way whereHas resolution BudgetForecastService already used.
 * Also covers the new pm:generate-schedules Artisan command — the real cron
 * equivalent of what TaskController::pmSchedule() does on every page load.
 */
class PmScheduleOutsideLineAndGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::Engineer->value);
        $this->user->branches()->attach($this->branch->id);
    }

    private function outsideLineEquipment(Branch $branch): Equipment
    {
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $branch->id]);

        return Equipment::factory()->create(['machine_id' => $machine->id]);
    }

    public function test_recurring_weekly_task_library_on_outside_line_equipment_is_auto_scheduled(): void
    {
        $equipment = $this->outsideLineEquipment($this->branch);

        $library = TaskLibrary::create([
            'equipment_id' => $equipment->id,
            'code' => 'TL-OUT-1',
            'title' => 'Cek Mingguan',
            'schedule_type' => 'calendar',
            'interval_days' => 7,
            'is_active' => true,
        ]);

        app(PmSchedulingService::class)->ensureRecurringSchedules($this->branch);

        $this->assertSame(1, Task::where('task_library_id', $library->id)->count());
    }

    public function test_pm_tasks_endpoint_includes_tasks_for_outside_line_equipment(): void
    {
        $equipment = $this->outsideLineEquipment($this->branch);

        $task = Task::create([
            'equipment_id' => $equipment->id,
            'title' => 'WO Luar Line',
            'status' => TaskStatus::Pending,
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/branches/{$this->branch->id}/pm-tasks")->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($task->id));
    }

    public function test_generate_pm_schedules_command_only_covers_active_branches(): void
    {
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);

        $activeEquipment = $this->equipmentUnderLine($this->branch);
        $inactiveEquipment = $this->equipmentUnderLine($inactiveBranch);

        $activeLibrary = TaskLibrary::create([
            'equipment_id' => $activeEquipment->id,
            'code' => 'TL-ACT-1',
            'title' => 'Cek Mingguan Aktif',
            'schedule_type' => 'calendar',
            'interval_days' => 7,
            'is_active' => true,
        ]);

        $inactiveLibrary = TaskLibrary::create([
            'equipment_id' => $inactiveEquipment->id,
            'code' => 'TL-INACT-1',
            'title' => 'Cek Mingguan Nonaktif',
            'schedule_type' => 'calendar',
            'interval_days' => 7,
            'is_active' => true,
        ]);

        $this->artisan('pm:generate-schedules')->assertSuccessful();

        $this->assertSame(1, Task::where('task_library_id', $activeLibrary->id)->count());
        $this->assertSame(0, Task::where('task_library_id', $inactiveLibrary->id)->count());
    }

    private function equipmentUnderLine(Branch $branch): Equipment
    {
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        return Equipment::factory()->create(['machine_id' => $machine->id]);
    }
}
