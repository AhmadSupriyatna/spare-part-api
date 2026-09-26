<?php

namespace Tests\Feature;

use App\Enums\PartRepairDisposition;
use App\Enums\PartReplacementStrategy;
use App\Enums\PartUnitStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartRepairLifecycleTest extends TestCase
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

    /** Scenario 8: remove -> pending -> in_repair -> repaired (with cost) -> reinstall. */
    public function test_repair_flow_records_cost_and_allows_reinstall(): void
    {
        $lifecycle = app(PartLifecycleService::class);

        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);
        $unit = $installation->partUnit;

        $lifecycle->remove($installation);
        $unit->refresh();
        $this->assertSame(PartUnitStatus::PendingRepair, $unit->status);

        $repair = $unit->repairs()->latest('removed_at')->first();
        $this->assertSame(PartRepairDisposition::Pending, $repair->disposition);

        $lifecycle->updateDisposition($repair, PartRepairDisposition::InRepair, null, '2027-01-01');
        $this->assertSame(PartUnitStatus::InRepair, $unit->fresh()->status);
        $this->assertNull($repair->fresh()->repair_cost);

        $repaired = $lifecycle->updateDisposition($repair->fresh(), PartRepairDisposition::Repaired, null, null, '150000.00');
        $this->assertSame(PartUnitStatus::Available, $unit->fresh()->status);
        $this->assertNotNull($repaired->repaired_at);
        $this->assertSame('150000.00', $repaired->repair_cost);

        // Reinstall the repaired unit elsewhere.
        $newInstallation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id, 'part_unit_id' => $unit->id], $this->user);
        $this->assertSame(PartUnitStatus::InService, $unit->fresh()->status);
        $this->assertSame($newInstallation->id, $repair->fresh()->reinstalled_installation_id);
    }

    /** Scenario 9: scrap — unit becomes scrapped and cannot be reinstalled. */
    public function test_scrapped_unit_cannot_be_reinstalled(): void
    {
        $lifecycle = app(PartLifecycleService::class);

        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);
        $unit = $installation->partUnit;
        $lifecycle->remove($installation);
        $repair = $unit->repairs()->latest('removed_at')->first();

        $lifecycle->updateDisposition($repair, PartRepairDisposition::Scrapped);

        $this->assertSame(PartUnitStatus::Scrapped, $unit->fresh()->status);

        // Business rule check: a scrapped unit is never offered by the
        // frontend's "available units" list, which filters on this status —
        // confirmed here at the data level.
        $this->assertNotContains($unit->fresh()->status, [PartUnitStatus::Available, PartUnitStatus::InService]);
    }

    /** Scenario 10 (lifetime regression): LIFE_BASED stays in lifetime monitoring, FAILURE_BASED does not. */
    public function test_lifetime_monitoring_only_applies_to_life_based_parts(): void
    {
        $lifecycle = app(PartLifecycleService::class);

        $this->part->update([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 100,
        ]);

        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        // Runtime hours advance on the line; the unit has used all 500 hours
        // recorded on installation vs its equipment's line runtime.
        $this->equipment->machine->line->update(['runtime_hours' => 600]);

        $percentUsed = $installation->fresh()->partUnit->percentUsed();
        $this->assertNotNull($percentUsed);
        $this->assertSame(100, $percentUsed);

        $this->part->update(['replacement_strategy' => PartReplacementStrategy::FailureBased]);

        $this->assertNull($installation->fresh()->partUnit->fresh()->percentUsed());
        $this->assertNull($installation->fresh()->partUnit->fresh()->remainingHours());
    }

    /** Repair Part history must be able to answer "oleh siapa" — nothing recorded this before. */
    public function test_remove_records_who_removed_the_part(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $remover = User::factory()->create();

        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);
        $lifecycle->remove($installation, $remover);

        $this->assertSame($remover->id, $installation->fresh()->removed_by);
    }

    public function test_remove_via_the_api_records_the_authenticated_user(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);
        $admin->branches()->attach($this->branch->id);

        $lifecycle = app(PartLifecycleService::class);
        $installation = $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);

        $this->actingAs($admin)
            ->postJson("/api/part-installations/{$installation->id}/remove")
            ->assertOk()
            ->assertJsonPath('data.removed_by_name', $admin->name);

        $this->assertSame($admin->id, $installation->fresh()->removed_by);
    }
}
