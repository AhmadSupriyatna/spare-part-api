<?php

namespace Tests\Feature;

use App\Enums\Fp3Status;
use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Fp3Request;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task::isOverdue()/Fp3Request::isOverdue() used to call `->isPast()` on
 * due_date, which compares against the current instant rather than just
 * today's date — due_date hydrates as midnight of the due day, so the
 * moment the clock passed midnight ON the due date itself, a task due
 * "today" was already misreported as overdue for the rest of that day.
 */
class OverdueTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_task_due_today_is_not_overdue(): void
    {
        $equipment = Equipment::factory()->create();
        $task = Task::factory()->for($equipment)->create([
            'status' => TaskStatus::Pending,
            'due_date' => today(),
        ]);

        $this->assertFalse($task->isOverdue());
    }

    public function test_a_pending_task_due_yesterday_is_overdue(): void
    {
        $equipment = Equipment::factory()->create();
        $task = Task::factory()->for($equipment)->create([
            'status' => TaskStatus::Pending,
            'due_date' => today()->subDay(),
        ]);

        $this->assertTrue($task->isOverdue());
    }

    public function test_a_pending_task_due_tomorrow_is_not_overdue(): void
    {
        $equipment = Equipment::factory()->create();
        $task = Task::factory()->for($equipment)->create([
            'status' => TaskStatus::Pending,
            'due_date' => today()->addDay(),
        ]);

        $this->assertFalse($task->isOverdue());
    }

    public function test_a_completed_task_due_yesterday_is_not_overdue(): void
    {
        $equipment = Equipment::factory()->create();
        $task = Task::factory()->for($equipment)->create([
            'status' => TaskStatus::Completed,
            'due_date' => today()->subDay(),
        ]);

        $this->assertFalse($task->isOverdue());
    }

    public function test_an_fp3_request_due_today_is_not_overdue(): void
    {
        $branch = Branch::factory()->create();
        $fp3 = Fp3Request::create([
            'branch_id' => $branch->id,
            'code' => Fp3Request::generateCode($branch->id),
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah',
            'request_photo_path' => 'fp3/fake.webp',
            'status' => Fp3Status::Pending,
            'due_date' => today(),
        ]);

        $this->assertFalse($fp3->isOverdue());
    }

    public function test_an_fp3_request_due_yesterday_is_overdue(): void
    {
        $branch = Branch::factory()->create();
        $fp3 = Fp3Request::create([
            'branch_id' => $branch->id,
            'code' => Fp3Request::generateCode($branch->id),
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah',
            'request_photo_path' => 'fp3/fake.webp',
            'status' => Fp3Status::Pending,
            'due_date' => today()->subDay(),
        ]);

        $this->assertTrue($fp3->isOverdue());
    }
}
