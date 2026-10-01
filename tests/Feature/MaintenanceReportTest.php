<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\LineRuntimeLog;
use App\Models\Machine;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Pemeriksaan Mesin's QR Validator (public, no login — see
 * PublicMaintenanceReportController), the "SPV Teknik" name it and the
 * printed report both look up (UserController::supervisorForBranch()), and
 * its period-aware Running Hours (LineController::runtimeAsOf()).
 */
class MaintenanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private Machine $machine;

    private Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $this->machine = Machine::factory()->create(['line_id' => $this->line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $this->machine->id]);
    }

    public function test_public_report_for_machine_requires_no_authentication(): void
    {
        $task = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => now(),
        ]);

        $response = $this->getJson('/api/public/wo-report?'.http_build_query([
            'scope' => 'machine',
            'id' => $this->machine->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.machine_name', $this->machine->name);
        $response->assertJsonPath('data.branch_name', $this->branch->name);
        $ids = collect($response->json('data.tasks'))->pluck('id');
        $this->assertTrue($ids->contains($task->id));
    }

    public function test_public_report_for_line_aggregates_its_machines_without_authentication(): void
    {
        $task = Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'due_date' => now(),
        ]);

        $response = $this->getJson('/api/public/wo-report?'.http_build_query([
            'scope' => 'line',
            'id' => $this->line->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.line_name', $this->line->name);
        $ids = collect($response->json('data.tasks'))->pluck('id');
        $this->assertTrue($ids->contains($task->id));
    }

    public function test_supervisor_for_branch_returns_the_registered_supervisors_name(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);
        $admin->branches()->attach($this->branch->id);

        $supervisor = User::factory()->create(['name' => 'Budi Santoso']);
        $supervisor->assignRole(UserRole::Supervisor->value);
        $supervisor->branches()->attach($this->branch->id);

        $response = $this->actingAs($admin)->getJson("/api/branches/{$this->branch->id}/supervisor");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Budi Santoso');
    }

    public function test_supervisor_for_branch_is_null_when_none_assigned(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);
        $admin->branches()->attach($this->branch->id);

        $response = $this->actingAs($admin)->getJson("/api/branches/{$this->branch->id}/supervisor");

        $response->assertOk();
        $response->assertJsonPath('data.name', null);
    }

    public function test_runtime_as_of_returns_the_latest_log_at_or_before_the_given_date_not_the_live_total(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);
        $admin->branches()->attach($this->branch->id);

        // created_at isn't mass-assignable, so it's forced in after create() —
        // otherwise Eloquent's own timestamp management would stamp all three
        // as "now", making the "at or before" query meaningless to test.
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 100,
            'new_hours' => 200,
            'hours_added' => 100,
        ])->forceFill(['created_at' => now()->subDays(10)])->save();
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 200,
            'new_hours' => 350,
            'hours_added' => 150,
        ])->forceFill(['created_at' => now()->subDays(3)])->save();
        // After the queried date — must not be picked, proving this isn't just "the latest log overall".
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 350,
            'new_hours' => 999,
            'hours_added' => 649,
        ]);

        $response = $this->actingAs($admin)->getJson(
            "/api/lines/{$this->line->id}/runtime-at?date=".now()->subDays(5)->toDateString()
        );

        $response->assertOk();
        $response->assertJsonPath('data.hours', 200);
    }

    public function test_runtime_as_of_is_null_when_no_log_exists_that_far_back(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);
        $admin->branches()->attach($this->branch->id);

        $response = $this->actingAs($admin)->getJson(
            "/api/lines/{$this->line->id}/runtime-at?date=".now()->subYear()->toDateString()
        );

        $response->assertOk();
        $response->assertJsonPath('data.hours', null);
    }
}
