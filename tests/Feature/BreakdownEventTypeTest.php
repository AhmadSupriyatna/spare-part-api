<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\ReplacementRequestEventType;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartReplacementRequest;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QR Scan stays one door for the operator either way — this only checks
 * that what lands in the database as event_type is decided from the part's
 * own replacement_strategy, server-side, and can't be steered by the client.
 */
class BreakdownEventTypeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->user = User::factory()->create();
    }

    /**
     * @return array<string, array{PartReplacementStrategy, ReplacementRequestEventType}>
     */
    public static function strategyProvider(): array
    {
        return [
            'FAILURE_BASED -> FAILURE' => [PartReplacementStrategy::FailureBased, ReplacementRequestEventType::Failure],
            'LIFE_BASED -> BREAKDOWN' => [PartReplacementStrategy::LifeBased, ReplacementRequestEventType::Breakdown],
            'SCHEDULED -> BREAKDOWN' => [PartReplacementStrategy::Scheduled, ReplacementRequestEventType::Breakdown],
        ];
    }

    #[DataProvider('strategyProvider')]
    public function test_event_type_is_derived_from_replacement_strategy(
        PartReplacementStrategy $strategy,
        ReplacementRequestEventType $expected,
    ): void {
        $part = Part::factory()->create(['replacement_strategy' => $strategy]);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);

        $response = $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
        ])->assertCreated();

        $request = PartReplacementRequest::findOrFail($response->json('data.id'));
        $this->assertSame($expected, $request->event_type);
    }

    /** Quantity validation (from the previous gap fix) must keep working for a FAILURE event too. */
    public function test_quantity_validation_still_applies_to_failure_events(): void
    {
        $part = Part::factory()->create(['replacement_strategy' => PartReplacementStrategy::FailureBased]);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);

        $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_used');

        $this->assertSame(0, PartReplacementRequest::count());
    }

    /** And for a BREAKDOWN event (any non-FailureBased strategy). */
    public function test_quantity_validation_still_applies_to_breakdown_events(): void
    {
        $part = Part::factory()->create(['replacement_strategy' => PartReplacementStrategy::LifeBased]);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);

        $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity_used');

        $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 2,
        ])->assertCreated();
    }

    /** A client can't force event_type — it isn't even a validated field. */
    public function test_client_supplied_event_type_is_ignored(): void
    {
        $part = Part::factory()->create(['replacement_strategy' => PartReplacementStrategy::FailureBased]);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);

        $response = $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
            'event_type' => 'breakdown', // attempted override
        ])->assertCreated();

        $request = PartReplacementRequest::findOrFail($response->json('data.id'));
        $this->assertSame(ReplacementRequestEventType::Failure, $request->event_type, 'Client-supplied event_type must be ignored.');
    }

    public function test_client_cannot_force_life_based_into_failure_event(): void
    {
        $part = Part::factory()->create(['replacement_strategy' => PartReplacementStrategy::LifeBased]);
        app(PartLifecycleService::class)->install($this->equipment, ['part_id' => $part->id], $this->user);

        $response = $this->postJson("/api/public/branches/{$this->branch->id}/replacement-requests", [
            'part_id' => $part->id,
            'equipment_id' => $this->equipment->id,
            'requested_by_name' => 'Teknisi UAT',
            'quantity_used' => 1,
            'event_type' => 'failure', // attempted override
        ])->assertCreated();

        $request = PartReplacementRequest::findOrFail($response->json('data.id'));
        $this->assertSame(ReplacementRequestEventType::Breakdown, $request->event_type, 'Client-supplied event_type must be ignored.');
    }
}
