<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\LineRuntimeLog;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Input kWh" per Line, per calendar week — CompanySetting::kwh_input_mode
 * switches between a cumulative meter reading (mirrors Jam Operasional)
 * and the week's usage typed directly. See LineController::addKwh().
 */
class LineKwhLogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private ProductionLine $line;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        CompanySetting::current();

        $this->branch = Branch::factory()->create();
        $this->line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);
    }

    public function test_direct_mode_stores_the_value_as_is(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 1200,
            'notes' => 'Minggu normal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.mode', 'direct');
        $response->assertJsonPath('data.kwh_used', 1200);
        $response->assertJsonPath('data.previous_reading', null);
        $response->assertJsonPath('data.new_reading', null);
        $response->assertJsonPath('data.week_start_date', Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY)->toDateString());
    }

    public function test_reading_mode_computes_the_delta_against_the_previous_week(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'reading']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 5000,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-12',
            'value' => 5800,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.mode', 'reading');
        $response->assertJsonPath('data.previous_reading', 5000);
        $response->assertJsonPath('data.new_reading', 5800);
        $response->assertJsonPath('data.kwh_used', 800);
    }

    public function test_reading_mode_rejects_a_value_lower_than_the_previous_week(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'reading']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 5000,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-12',
            'value' => 4000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('value');
    }

    public function test_resubmitting_the_same_week_corrects_it_instead_of_duplicating(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 1000,
        ])->assertCreated();

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-07',
            'value' => 1100,
        ])->assertOk();

        $this->assertSame(1, $this->line->kwhLogs()->count());
        $this->assertSame(1100, $this->line->kwhLogs()->first()->kwh_used);
    }

    public function test_correcting_a_week_keeps_its_previous_notes_when_none_are_resubmitted(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 1000,
            'notes' => 'Dibaca shift pagi',
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-07',
            'value' => 1100,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.notes', 'Dibaca shift pagi');
    }

    public function test_direct_mode_stores_kva_as_is_and_derives_pf(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 850,
            'kva_value' => 1000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.kwh_used', 850);
        $response->assertJsonPath('data.kva_used', 1000);
        $response->assertJsonPath('data.previous_kva_reading', null);
        $response->assertJsonPath('data.new_kva_reading', null);
        $response->assertJsonPath('data.pf', 0.85);
    }

    public function test_reading_mode_computes_kva_delta_and_derives_pf(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'reading']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 5000,
            'kva_value' => 6000,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-12',
            'value' => 5800,
            'kva_value' => 6940,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.previous_kva_reading', 6000);
        $response->assertJsonPath('data.new_kva_reading', 6940);
        $response->assertJsonPath('data.kva_used', 940);
        // kwh_used = 800, kva_used = 940 -> pf = 0.851 (rounded to 3 decimals)
        $response->assertJsonPath('data.pf', 0.851);
    }

    public function test_kva_is_optional_and_pf_stays_null_without_it(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 850,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.kva_used', null);
        $response->assertJsonPath('data.pf', null);
    }

    public function test_reading_mode_rejects_a_kva_value_lower_than_the_previous_week(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'reading']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 5000,
            'kva_value' => 6000,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-12',
            'value' => 5800,
            'kva_value' => 5000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('kva_value');
    }

    public function test_kwh_logs_are_listed_for_the_line(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);

        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 900,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->getJson("/api/lines/{$this->line->id}/kwh-logs");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.kwh_used', 900);
        $response->assertJsonPath('data.0.recorded_by_name', $this->engineer->name);
    }

    public function test_kwh_report_totals_every_line_for_the_chosen_month(): void
    {
        CompanySetting::current()->update(['kwh_input_mode' => 'direct']);
        $otherLine = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);

        // Two weeks in October land in the report; one in September doesn't.
        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-05',
            'value' => 500,
        ])->assertCreated();
        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-10-19',
            'value' => 300,
        ])->assertCreated();
        $this->actingAs($this->engineer)->postJson("/api/lines/{$this->line->id}/kwh", [
            'for_date' => '2026-09-28',
            'value' => 999,
        ])->assertCreated();

        $response = $this->actingAs($this->engineer)->getJson(
            "/api/branches/{$this->branch->id}/lines/kwh-report?month=2026-10"
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $row = collect($response->json('data'))->firstWhere('line_id', $this->line->id);
        $this->assertSame(800, $row['total_kwh']);
        $this->assertSame(2, $row['entry_count']);
        $otherRow = collect($response->json('data'))->firstWhere('line_id', $otherLine->id);
        $this->assertSame(0, $otherRow['total_kwh']);
    }

    public function test_runtime_report_sums_hours_added_within_the_chosen_month(): void
    {
        // created_at isn't mass-assignable, so it's forced in after create().
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 100,
            'new_hours' => 150,
            'hours_added' => 50,
        ])->forceFill(['created_at' => Carbon::parse('2026-10-10')])->save();
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 150,
            'new_hours' => 180,
            'hours_added' => 30,
        ])->forceFill(['created_at' => Carbon::parse('2026-10-20')])->save();
        LineRuntimeLog::create([
            'line_id' => $this->line->id,
            'previous_hours' => 50,
            'new_hours' => 100,
            'hours_added' => 50,
        ])->forceFill(['created_at' => Carbon::parse('2026-09-15')])->save();

        $response = $this->actingAs($this->engineer)->getJson(
            "/api/branches/{$this->branch->id}/lines/runtime-report?month=2026-10"
        );

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('line_id', $this->line->id);
        $this->assertSame(80, $row['hours_added']);
        $this->assertSame(100, $row['hours_at_start']);
        $this->assertSame(180, $row['hours_at_end']);
    }
}
