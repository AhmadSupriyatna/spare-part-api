<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Monitoring Life Time Mesin" — the unauthenticated QR-scan landing page
 * for a Machine. No login required (unlike almost everything else in this
 * app), by explicit request — mirrors PublicBreakdownController's access
 * level. Covers both a production Machine and an outside-line one ("Mesin
 * Luar Line"), since PublicMachineMonitoringController scopes purely by
 * equipment_id and never walks machine->line itself.
 */
class PublicMachineMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_endpoint_requires_no_authentication(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id]);

        $this->getJson("/api/public/machines/{$machine->id}/monitoring")->assertOk();
    }

    public function test_monitoring_includes_machine_identity(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id, 'name' => 'Mesin Press']);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $response->assertOk();
        $this->assertSame('Mesin Press', $response->json('data.machine.name'));
    }

    public function test_monitoring_works_for_an_outside_line_machine(): void
    {
        $branch = Branch::factory()->create();
        $machine = Machine::factory()->create(['line_id' => null, 'branch_id' => $branch->id, 'name' => 'Genset']);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $response->assertOk();
        $this->assertSame('Genset', $response->json('data.machine.name'));
        $this->assertSame($branch->id, $response->json('data.machine.branch_id'));
    }

    public function test_monitoring_lists_upcoming_open_tasks_for_the_machines_equipment(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Pending, 'title' => 'Cek Oli']);
        Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Completed, 'title' => 'Sudah Selesai']);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $titles = collect($response->json('data.upcoming_tasks'))->pluck('title')->all();
        $this->assertContains('Cek Oli', $titles);
        $this->assertNotContains('Sudah Selesai', $titles);
    }

    public function test_monitoring_does_not_list_tasks_from_a_different_machine(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $otherMachine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        Task::factory()->create(['equipment_id' => $equipment->id, 'status' => TaskStatus::Pending, 'title' => 'Mine']);
        Task::factory()->create(['equipment_id' => $otherEquipment->id, 'status' => TaskStatus::Pending, 'title' => 'Not Mine']);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $titles = collect($response->json('data.upcoming_tasks'))->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Not Mine', $titles);
    }

    public function test_monitoring_lists_active_part_installations(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create(['name' => 'Bearing']);
        PartInstallation::create(['equipment_id' => $equipment->id, 'part_id' => $part->id]);
        $removedPart = Part::factory()->create(['name' => 'Removed Part']);
        PartInstallation::create([
            'equipment_id' => $equipment->id,
            'part_id' => $removedPart->id,
            'removed_at' => now(),
        ]);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $partNames = collect($response->json('data.installations'))->pluck('part_name')->all();
        $this->assertContains('Bearing', $partNames);
        $this->assertNotContains('Removed Part', $partNames);
    }

    public function test_monitoring_counts_failure_events_for_the_machines_equipment_into_the_monthly_trend(): void
    {
        $line = ProductionLine::factory()->create();
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create();
        PartReplacementRequest::create([
            'equipment_id' => $equipment->id,
            'part_id' => $part->id,
            'event_type' => 'failure',
            'status' => 'pending',
            'reason' => 'Rusak',
            'requested_by_name' => 'Teknisi',
            'quantity_used' => 1,
        ]);

        $response = $this->getJson("/api/public/machines/{$machine->id}/monitoring");

        $currentMonth = now()->format('Y-m');
        $trend = collect($response->json('data.monthly_failure_trend'))->keyBy('month');
        $this->assertSame(1, $trend[$currentMonth]['count']);
    }
}
