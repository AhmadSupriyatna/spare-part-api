<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression test for the N+1 fix in BudgetForecastService — listing
 * budgets used to re-fetch unit cost / avg weekly hours / PartInstallation
 * rows independently for lineBreakdown() AND machineBreakdown() of every
 * item, and again for every other item/budget referencing the same part
 * (see the class docblock's memoization caches). Rather than asserting a
 * brittle exact query count, this proves the caching actually fires: two
 * budgets that repeat the SAME 3 parts must cost noticeably fewer queries
 * than two budgets covering 6 DISTINCT parts, even though both scenarios
 * render the same total number of budget items (6). All fixture setup
 * (including the POST /budgets calls, which run this exact same pricing
 * logic once at creation time) happens BEFORE the query log is ever
 * enabled — only the two GET /budgets calls being compared are measured.
 */
class BudgetForecastQueryCountTest extends TestCase
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

    private function installedLifeBasedPart(Branch $branch): Part
    {
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 1000,
        ]);
        PartStock::factory()->for($part)->for($branch)->create(['unit_cost' => 100]);
        app(PartLifecycleService::class)->install($equipment, ['part_id' => $part->id], $this->admin);

        return $part;
    }

    /** @param  list<int>  $partIds */
    private function createBudget(Branch $branch, int $year, array $partIds): void
    {
        $this->actingAs($this->admin)->postJson("/api/branches/{$branch->id}/budgets", [
            'replacement_strategy' => 'life_based',
            'year' => $year,
            'global_lifetime_percent' => 100,
            'global_price_increase_percent' => 0,
            'items' => collect($partIds)->map(fn (int $id) => ['part_id' => $id])->all(),
        ])->assertCreated();
    }

    public function test_listing_budgets_costs_fewer_queries_when_items_share_parts_than_when_they_dont(): void
    {
        // --- Fixture setup for BOTH scenarios happens first, with query logging off. ---

        $sharedBranch = Branch::factory()->create();
        $this->admin->branches()->attach($sharedBranch->id);
        $sharedPartIds = [
            $this->installedLifeBasedPart($sharedBranch)->id,
            $this->installedLifeBasedPart($sharedBranch)->id,
            $this->installedLifeBasedPart($sharedBranch)->id,
        ];
        // Two budgets, same 3 parts repeated in both — 6 items total, 3 distinct parts.
        $this->createBudget($sharedBranch, 2026, $sharedPartIds);
        $this->createBudget($sharedBranch, 2027, $sharedPartIds);

        $distinctBranch = Branch::factory()->create();
        $this->admin->branches()->attach($distinctBranch->id);
        // Two budgets, 6 DISTINCT parts (3 each) — same 6 items total, nothing to share.
        $this->createBudget($distinctBranch, 2026, [
            $this->installedLifeBasedPart($distinctBranch)->id,
            $this->installedLifeBasedPart($distinctBranch)->id,
            $this->installedLifeBasedPart($distinctBranch)->id,
        ]);
        $this->createBudget($distinctBranch, 2027, [
            $this->installedLifeBasedPart($distinctBranch)->id,
            $this->installedLifeBasedPart($distinctBranch)->id,
            $this->installedLifeBasedPart($distinctBranch)->id,
        ]);

        // --- Only the two GET calls being compared are measured. ---

        DB::enableQueryLog();

        $this->actingAs($this->admin)->getJson("/api/branches/{$sharedBranch->id}/budgets")->assertOk();
        $sharedPartsQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->actingAs($this->admin)->getJson("/api/branches/{$distinctBranch->id}/budgets")->assertOk();
        $distinctPartsQueryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertLessThan(
            $distinctPartsQueryCount,
            $sharedPartsQueryCount,
            'Repeating the same parts across budget items should reuse cached per-part lookups and installation fetches, costing fewer queries than an equal number of items over distinct parts.'
        );
    }
}
