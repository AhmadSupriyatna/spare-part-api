<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UAT gap: a Breakdown request could be created with quantity_used greater
 * than how many units of that part were actually active on that equipment —
 * it only failed later, at Approval. This closes it at creation time (see
 * StoreReplacementRequestRequest::withValidator()).
 */
class BreakdownRequestQuantityValidationTest extends TestCase
{
    use RefreshDatabase;

    private Equipment $equipment;

    private Part $part;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->user = User::factory()->create();
    }

    private function installActiveUnits(int $count): void
    {
        $lifecycle = app(PartLifecycleService::class);

        for ($i = 0; $i < $count; $i++) {
            $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->user);
        }
    }

    private function submit(int $quantityUsed): TestResponse
    {
        return $this->postJson('/api/public/replacement-requests', [
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => $quantityUsed,
            'reason' => 'UAT regression test',
        ]);
    }

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, PartReplacementRequest::count());
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, PartRepair::count());
        $this->assertSame(
            0,
            PartInstallation::whereNotNull('removed_at')->count(),
            'No installation should have been removed by a rejected request.'
        );
    }

    /** 1. active = 1, requested = 1 -> PASS */
    public function test_quantity_equal_to_active_count_is_accepted(): void
    {
        $this->installActiveUnits(1);

        $this->submit(1)->assertCreated();

        $this->assertSame(1, PartReplacementRequest::count());
    }

    /** 2. active = 1, requested = 2 -> REJECT */
    public function test_quantity_exceeding_single_active_installation_is_rejected(): void
    {
        $this->installActiveUnits(1);

        $this->submit(2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_used');

        $this->assertNothingPersisted();
    }

    /** 3. active = 1, requested = 3 -> REJECT */
    public function test_quantity_far_exceeding_single_active_installation_is_rejected(): void
    {
        $this->installActiveUnits(1);

        $this->submit(3)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_used');

        $this->assertNothingPersisted();
    }

    /** 4. active = 3, requested = 3 -> PASS */
    public function test_quantity_equal_to_three_active_installations_is_accepted(): void
    {
        $this->installActiveUnits(3);

        $this->submit(3)->assertCreated();

        $this->assertSame(1, PartReplacementRequest::count());
    }

    /** 5. active = 3, requested = 4 -> REJECT */
    public function test_quantity_exceeding_three_active_installations_is_rejected(): void
    {
        $this->installActiveUnits(3);

        $this->submit(4)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity_used');

        $this->assertNothingPersisted();
    }

    /** 6. active = 0, any positive quantity -> REJECT */
    public function test_any_positive_quantity_is_rejected_when_nothing_is_active(): void
    {
        // No installations at all — equipment_id itself already fails the
        // existing "part is installed here" rule, before quantity is even
        // considered.
        $this->submit(1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment_id');

        $this->assertNothingPersisted();
    }

    /** Removed installations don't count as "active" for this check. */
    public function test_removed_installations_do_not_count_towards_the_active_total(): void
    {
        $this->installActiveUnits(1);
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->remove(PartInstallation::first());

        // Equipment no longer has any active installation of this part.
        $this->submit(1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment_id');

        $this->assertSame(0, PartReplacementRequest::count());
    }
}
