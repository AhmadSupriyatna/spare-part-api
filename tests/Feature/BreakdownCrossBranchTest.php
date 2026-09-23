<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit gap: the branch encoded in a printed QR's own URL
 * (/breakdown/scan/:partId/:branchId) was never actually enforced server
 * side — a direct API call could submit an equipment_id from a completely
 * different plant. Fixed by making the store endpoint branch-scoped (same
 * pattern as equipmentForPartInBranch()) and cross-checking equipment's
 * real branch in StoreReplacementRequestRequest::withValidator().
 */
class BreakdownCrossBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @return array{branch: Branch, equipment: Equipment}
     */
    private function makeEquipmentInBranch(): array
    {
        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);

        return ['branch' => $branch, 'equipment' => $equipment];
    }

    public function test_request_scoped_to_the_correct_branch_is_accepted(): void
    {
        ['branch' => $branch, 'equipment' => $equipment] = $this->makeEquipmentInBranch();
        $part = Part::factory()->create();
        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $this->user);

        $this->postJson("/api/public/branches/{$branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
        ])->assertCreated();

        $this->assertSame(1, PartReplacementRequest::count());
    }

    /**
     * The exact attack this closes: scan Plant A's QR (branch A in the URL)
     * but submit an equipment_id that actually belongs to Plant B.
     */
    public function test_equipment_from_a_different_branch_than_the_scanned_qr_is_rejected(): void
    {
        ['branch' => $branchA] = $this->makeEquipmentInBranch();
        ['equipment' => $equipmentB] = $this->makeEquipmentInBranch();

        $part = Part::factory()->create();
        app(PartLifecycleService::class)->install($equipmentB, ['part_id' => $part->id], $this->user);

        $this->postJson("/api/public/branches/{$branchA->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $equipmentB->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('equipment_id');

        $this->assertSame(0, PartReplacementRequest::count());
    }

    public function test_a_branch_id_that_does_not_exist_is_rejected(): void
    {
        ['equipment' => $equipment] = $this->makeEquipmentInBranch();
        $part = Part::factory()->create();
        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $this->user);

        $this->postJson('/api/public/branches/999999/replacement-requests', [
            'part_id' => $part->id,
            'equipment_id' => $equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
        ])->assertNotFound();

        $this->assertSame(0, PartReplacementRequest::count());
    }
}
