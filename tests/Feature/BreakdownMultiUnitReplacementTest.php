<?php

namespace Tests\Feature;

use App\Enums\ReplacementRequestStatus;
use App\Exceptions\AmbiguousInstallationException;
use App\Exceptions\InvalidReplacementSelectionException;
use App\Exceptions\ReplacementRequestAlreadyReviewedException;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartInstallation;
use App\Models\PartRepair;
use App\Models\PartReplacementRequest;
use App\Models\PartReplacementRequestItem;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\PartLifecycleService;
use App\Services\PartReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakdownMultiUnitReplacementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Part $part;

    private Equipment $equipment;

    private PartStock $stock;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->stock = PartStock::factory()->create([
            'part_id' => $this->part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 100,
        ]);
        $this->user = User::factory()->create();
    }

    private function installUnit(?Equipment $equipment = null, ?Part $part = null): PartInstallation
    {
        return app(PartLifecycleService::class)->install(
            $equipment ?? $this->equipment,
            ['part_id' => ($part ?? $this->part)->id],
            $this->user,
        );
    }

    private function makeRequest(int $quantityUsed): PartReplacementRequest
    {
        return PartReplacementRequest::create([
            'part_id' => $this->part->id,
            'equipment_id' => $this->equipment->id,
            'quantity_used' => $quantityUsed,
            'requested_by_name' => 'Teknisi Uji',
            'status' => ReplacementRequestStatus::Pending,
        ]);
    }

    /** Scenario 1: quantity 1, one active installation. */
    public function test_quantity_1_with_single_active_installation_approves_normally(): void
    {
        $old = $this->installUnit();
        $request = $this->makeRequest(1);

        app(PartReplacementService::class)->approve($request, $this->user);

        $old->refresh();
        $this->assertNotNull($old->removed_at);
        $this->assertSame(1, PartInstallation::where('equipment_id', $this->equipment->id)->whereNull('removed_at')->count());
        $this->assertSame(99, $this->stock->fresh()->quantity_on_hand);
        $this->assertSame(1, $old->partUnit->repairs()->count());
        $this->assertSame(ReplacementRequestStatus::Approved, $request->fresh()->status);
        $this->assertSame(1, PartReplacementRequestItem::where('replacement_request_id', $request->id)->count());
    }

    /** Scenario 2: quantity 1, A/B/C present — must pick one. */
    public function test_quantity_1_with_several_active_installations_requires_explicit_pick(): void
    {
        $a = $this->installUnit();
        $b = $this->installUnit();
        $this->installUnit();
        $request = $this->makeRequest(1);

        $this->expectException(AmbiguousInstallationException::class);
        app(PartReplacementService::class)->approve($request, $this->user);
    }

    public function test_quantity_1_with_explicit_pick_only_removes_that_unit_and_inherits_slot(): void
    {
        $a = $this->installUnit();
        $b = $this->installUnit();
        $this->installUnit();
        $request = $this->makeRequest(1);

        app(PartReplacementService::class)->approve($request, $this->user, null, [$b->id]);

        $this->assertNull($a->fresh()->removed_at);
        $this->assertNotNull($b->fresh()->removed_at);

        $item = PartReplacementRequestItem::where('replacement_request_id', $request->id)->first();
        $this->assertSame($b->slot_label, $item->newInstallation->slot_label);
    }

    /** Scenario 3: quantity 3, A/B/C present, all three picked. */
    public function test_quantity_3_approves_all_three_atomically_with_slots_preserved(): void
    {
        $a = $this->installUnit();
        $b = $this->installUnit();
        $c = $this->installUnit();
        $request = $this->makeRequest(3);

        app(PartReplacementService::class)->approve($request, $this->user, null, [$a->id, $b->id, $c->id]);

        foreach ([$a, $b, $c] as $old) {
            $this->assertNotNull($old->fresh()->removed_at);
            $this->assertSame(1, $old->fresh()->partUnit->repairs()->count());
        }

        $items = PartReplacementRequestItem::where('replacement_request_id', $request->id)
            ->with('oldInstallation', 'newInstallation')->get();
        $this->assertCount(3, $items);

        $slotMap = $items->mapWithKeys(fn ($item) => [$item->oldInstallation->slot_label => $item->newInstallation->slot_label]);
        $this->assertSame('A', $slotMap['A']);
        $this->assertSame('B', $slotMap['B']);
        $this->assertSame('C', $slotMap['C']);

        $this->assertSame(3, PartInstallation::where('equipment_id', $this->equipment->id)->whereNull('removed_at')->count());
        $this->assertSame(97, $this->stock->fresh()->quantity_on_hand);
        $this->assertSame(1, StockLedger::where('part_stock_id', $this->stock->id)->count());
        $this->assertSame(ReplacementRequestStatus::Approved, $request->fresh()->status);
    }

    /** Scenario 4: quantity 3 but only 2 picked — rejected, nothing changes. */
    public function test_quantity_3_with_only_two_picked_is_rejected_and_changes_nothing(): void
    {
        $a = $this->installUnit();
        $b = $this->installUnit();
        $this->installUnit();
        $request = $this->makeRequest(3);

        try {
            app(PartReplacementService::class)->approve($request, $this->user, null, [$a->id, $b->id]);
            $this->fail('Expected InvalidReplacementSelectionException was not thrown.');
        } catch (InvalidReplacementSelectionException) {
            // expected
        }

        $this->assertSame(ReplacementRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(3, PartInstallation::where('equipment_id', $this->equipment->id)->whereNull('removed_at')->count());
        $this->assertSame(100, $this->stock->fresh()->quantity_on_hand);
        $this->assertSame(0, PartReplacementRequestItem::where('replacement_request_id', $request->id)->count());
    }

    /** Scenario 5: quantity 3 with a duplicate pick — rejected. */
    public function test_quantity_3_with_duplicate_pick_is_rejected(): void
    {
        $a = $this->installUnit();
        $this->installUnit();
        $this->installUnit();
        $request = $this->makeRequest(3);

        try {
            app(PartReplacementService::class)->approve($request, $this->user, null, [$a->id, $a->id, $a->id]);
            $this->fail('Expected InvalidReplacementSelectionException was not thrown.');
        } catch (InvalidReplacementSelectionException) {
            // expected
        }

        $this->assertSame(ReplacementRequestStatus::Pending, $request->fresh()->status);
    }

    /** Scenario 6: double-approve (concurrent/double click) — only the first succeeds. */
    public function test_approving_an_already_approved_request_is_rejected(): void
    {
        $this->installUnit();
        $request = $this->makeRequest(1);

        app(PartReplacementService::class)->approve($request, $this->user);

        $this->expectException(ReplacementRequestAlreadyReviewedException::class);
        app(PartReplacementService::class)->approve($request->fresh(), $this->user);
    }

    public function test_picking_an_installation_from_a_different_equipment_is_rejected(): void
    {
        $a = $this->installUnit();
        $b = $this->installUnit();
        $this->installUnit();

        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 100]);
        $otherMachine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        $foreignInstallation = $this->installUnit($otherEquipment, $this->part);

        $request = $this->makeRequest(3);

        $this->expectException(InvalidReplacementSelectionException::class);
        app(PartReplacementService::class)->approve($request, $this->user, null, [$a->id, $b->id, $foreignInstallation->id]);
    }

    /**
     * Finding: for quantity_used = 1, an explicit old_installation_id used
     * to be trusted at face value (PartInstallation::whereKey($id)->first(),
     * no ownership check at all) — closed so the same
     * equipment/part/active rules apply here as they already did for the
     * quantity > 1 path.
     */

    /** 1. qty=1 + valid installation -> PASS (already covered above, restated here for the finding's own numbering). */
    public function test_finding3_quantity_1_explicit_valid_installation_passes(): void
    {
        $unit = $this->installUnit();
        $request = $this->makeRequest(1);

        app(PartReplacementService::class)->approve($request, $this->user, null, [$unit->id]);

        $this->assertNotNull($unit->fresh()->removed_at);
        $this->assertSame(ReplacementRequestStatus::Approved, $request->fresh()->status);
    }

    /** 2. qty=1 + installation belongs to a different equipment -> REJECT, nothing changes. */
    public function test_finding3_quantity_1_explicit_installation_from_different_equipment_is_rejected(): void
    {
        $this->installUnit();

        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 100]);
        $otherMachine = Machine::factory()->create(['line_id' => $otherLine->id]);
        $otherEquipment = Equipment::factory()->create(['machine_id' => $otherMachine->id]);
        $foreignInstallation = $this->installUnit($otherEquipment, $this->part);

        $request = $this->makeRequest(1);

        try {
            app(PartReplacementService::class)->approve($request, $this->user, null, [$foreignInstallation->id]);
            $this->fail('Expected InvalidReplacementSelectionException was not thrown.');
        } catch (InvalidReplacementSelectionException) {
            // expected
        }

        $this->assertSame(ReplacementRequestStatus::Pending, $request->fresh()->status);
        $this->assertNull($foreignInstallation->fresh()->removed_at);
        $this->assertSame(0, PartReplacementRequestItem::where('replacement_request_id', $request->id)->count());
        $this->assertSame(0, StockLedger::count());
        $this->assertSame(0, PartRepair::count());
    }

    /** 3. qty=1 + installation belongs to a different part -> REJECT, nothing changes. */
    public function test_finding3_quantity_1_explicit_installation_from_different_part_is_rejected(): void
    {
        $this->installUnit();

        $otherPart = Part::factory()->create();
        $otherPartInstallation = $this->installUnit($this->equipment, $otherPart);

        $request = $this->makeRequest(1);

        try {
            app(PartReplacementService::class)->approve($request, $this->user, null, [$otherPartInstallation->id]);
            $this->fail('Expected InvalidReplacementSelectionException was not thrown.');
        } catch (InvalidReplacementSelectionException) {
            // expected
        }

        $this->assertSame(ReplacementRequestStatus::Pending, $request->fresh()->status);
        $this->assertNull($otherPartInstallation->fresh()->removed_at);
        $this->assertSame(0, PartReplacementRequestItem::where('replacement_request_id', $request->id)->count());
    }

    /** 4. qty=1 + installation already removed -> REJECT, nothing changes. */
    public function test_finding3_quantity_1_explicit_installation_already_removed_is_rejected(): void
    {
        $unit = $this->installUnit();
        app(PartLifecycleService::class)->remove($unit);
        $repairCountBefore = PartRepair::count();

        $request = $this->makeRequest(1);

        try {
            app(PartReplacementService::class)->approve($request, $this->user, null, [$unit->id]);
            $this->fail('Expected InvalidReplacementSelectionException was not thrown.');
        } catch (InvalidReplacementSelectionException) {
            // expected
        }

        $this->assertSame(ReplacementRequestStatus::Pending, $request->fresh()->status);
        $this->assertSame(0, PartReplacementRequestItem::where('replacement_request_id', $request->id)->count());
        $this->assertSame($repairCountBefore, PartRepair::count());
        $this->assertSame(0, StockLedger::count());
    }

    /** 5. Existing ambiguous qty=1 behaviour (no explicit pick, >1 active) is unchanged -> AmbiguousInstallationException. */
    public function test_finding3_quantity_1_ambiguous_without_pick_still_throws(): void
    {
        $this->installUnit();
        $this->installUnit();
        $request = $this->makeRequest(1);

        $this->expectException(AmbiguousInstallationException::class);
        app(PartReplacementService::class)->approve($request, $this->user);
    }
}
