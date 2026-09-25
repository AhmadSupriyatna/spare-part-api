<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\ProductionLine;
use App\Models\User;
use App\Services\BudgetForecastService;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Budget forecast's pcs/year estimate used to read one flat company-wide
 * avg_weekly_operating_hours (CompanySetting) regardless of which Line a
 * part's installations actually run on. It now averages
 * ProductionLine::effectiveAvgWeeklyOperatingHours() across the distinct
 * Lines that currently have an active installation of that part in the
 * branch (see BudgetForecastService::averageWeeklyOperatingHoursFor()).
 */
class BudgetForecastLineAverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rates_for_uses_the_average_of_lines_with_an_active_installation(): void
    {
        $branch = Branch::factory()->create();
        $lineA = ProductionLine::factory()->create(['branch_id' => $branch->id, 'avg_weekly_operating_hours' => 52]);
        $lineB = ProductionLine::factory()->create(['branch_id' => $branch->id, 'avg_weekly_operating_hours' => 104]);
        $equipmentA = Equipment::factory()->create(['machine_id' => Machine::factory()->create(['line_id' => $lineA->id])->id]);
        $equipmentB = Equipment::factory()->create(['machine_id' => Machine::factory()->create(['line_id' => $lineB->id])->id]);

        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 1000,
        ]);
        $user = User::factory()->create();
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($equipmentA, ['part_id' => $part->id], $user);
        $lifecycle->install($equipmentB, ['part_id' => $part->id], $user);

        $rates = app(BudgetForecastService::class)->ratesFor($branch, $part, 100, 0);

        // average(52, 104) = 78/week * 52 weeks / 1000 estimated hours.
        $expectedPcsPerYear = (78 * 52) / 1000;
        $this->assertEqualsWithDelta($expectedPcsPerYear, $rates['pcs_per_year'], 0.001);
    }

    public function test_rates_for_falls_back_to_company_default_when_the_part_has_no_installations(): void
    {
        $branch = Branch::factory()->create();
        CompanySetting::current()->update(['avg_weekly_operating_hours' => 40]);

        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 1000,
        ]);

        $rates = app(BudgetForecastService::class)->ratesFor($branch, $part, 100, 0);

        $expectedPcsPerYear = (40 * 52) / 1000;
        $this->assertEqualsWithDelta($expectedPcsPerYear, $rates['pcs_per_year'], 0.001);
    }

    public function test_rates_for_averages_each_distinct_line_only_once_regardless_of_installation_count(): void
    {
        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id, 'avg_weekly_operating_hours' => 60]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $equipmentA = Equipment::factory()->create(['machine_id' => $machine->id]);
        $equipmentB = Equipment::factory()->create(['machine_id' => $machine->id]);

        $part = Part::factory()->create([
            'replacement_strategy' => PartReplacementStrategy::LifeBased,
            'estimated_lifetime_hours' => 1000,
        ]);
        $user = User::factory()->create();
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($equipmentA, ['part_id' => $part->id], $user);
        $lifecycle->install($equipmentB, ['part_id' => $part->id], $user);

        $rates = app(BudgetForecastService::class)->ratesFor($branch, $part, 100, 0);

        // Two installations on the SAME line must not skew the average —
        // it's still just that one line's 60/week, not double-counted.
        $expectedPcsPerYear = (60 * 52) / 1000;
        $this->assertEqualsWithDelta($expectedPcsPerYear, $rates['pcs_per_year'], 0.001);
    }
}
