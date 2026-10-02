<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Machine Detail's mini calendar — one month's PM schedule (any status) and
 * failure/breakdown events, keyed by date. Authenticated only.
 */
class MachineCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
    }

    public function test_calendar_groups_tasks_and_failures_by_date_within_the_requested_month(): void
    {
        $line = ProductionLine::factory()->create();
        $this->admin->branches()->attach($line->branch_id);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();

        $today = now()->format('Y-m-d');
        $currentMonth = now()->format('Y-m');
        $nextMonth = now()->addMonthNoOverflow()->format('Y-m-d');
        Task::factory()->create(['equipment_id' => $equipment->id, 'due_date' => $today, 'title' => 'Ganti Oli']);
        PartReplacementRequest::create([
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'event_type' => 'breakdown',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);
        // A different month — must not appear.
        Task::factory()->create(['equipment_id' => $equipment->id, 'due_date' => $nextMonth, 'title' => 'Bulan Lain']);

        $response = $this->actingAs($this->admin)->getJson("/api/machines/{$machine->id}/calendar?month={$currentMonth}");

        $response->assertOk();
        $this->assertSame($currentMonth, $response->json('data.month'));
        $this->assertSame('Ganti Oli', $response->json("data.days.{$today}.pm.0.title"));
        $this->assertSame(1, $response->json("data.days.{$today}.failure_count"));
        $this->assertNull($response->json("data.days.{$nextMonth}"));
    }

    public function test_calendar_requires_authentication(): void
    {
        $machine = Machine::factory()->create();

        $this->getJson("/api/machines/{$machine->id}/calendar")->assertUnauthorized();
    }
}
