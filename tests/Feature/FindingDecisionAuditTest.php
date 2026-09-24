<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Finding;
use App\Models\Machine;
use App\Models\MaintenanceDecision;
use App\Models\Part;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\TaskPartCheck;
use App\Models\User;
use App\Support\BranchScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2, Step 7 — Finding and MaintenanceDecision now use the existing
 * Spatie Activitylog mechanism (LogsDefaultActivity), the same trait already
 * wired to PartInstallation/PartReplacementRequest/PartRepair/etc. No new
 * audit table, no new service — these tests only prove the existing
 * mechanism picked the two new models up correctly, with the right actor,
 * and without duplicating log rows.
 */
class FindingDecisionAuditTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private Part $part;

    private User $engineer;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id, 'runtime_hours' => 500]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();

        Role::findOrCreate(UserRole::Engineer->value, 'web');
        Role::findOrCreate(UserRole::Supervisor->value, 'web');

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole(UserRole::Supervisor->value);
        $this->supervisor->branches()->attach($this->branch->id);
    }

    /**
     * Real jalur existing: Engineer completes a PM checklist row with a
     * structured finding — see ChecklistFindingIntegrationTest for the
     * non-audit-focused version of this same flow.
     */
    private function completeChecklistWithFinding(): Finding
    {
        $task = Task::factory()->create(['equipment_id' => $this->equipment->id, 'status' => TaskStatus::Pending]);
        $check = TaskPartCheck::create([
            'task_id' => $task->id,
            'part_id' => $this->part->id,
            'quantity_planned' => 1,
        ]);

        $this->actingAs($this->engineer)->postJson("/api/tasks/{$task->id}/complete", [
            'checks' => [[
                'id' => $check->id,
                'part_id' => $this->part->id,
                'is_replaced' => false,
                'reason' => 'Observasi rutin.',
                'finding' => [
                    'description' => 'Bearing mulai terdengar kasar.',
                    'result' => 'monitor',
                    'severity' => 'low',
                ],
            ]],
        ])->assertOk();

        return Finding::firstOrFail();
    }

    // ---- A / B — Finding create activity + actor ----

    public function test_a_finding_creation_produces_an_activity_log_entry(): void
    {
        $this->completeChecklistWithFinding();

        $this->assertTrue(
            Activity::where('subject_type', Finding::class)->where('event', 'created')->exists()
        );
    }

    public function test_b_finding_creation_activity_causer_is_the_authenticated_engineer(): void
    {
        $finding = $this->completeChecklistWithFinding();

        $activity = Activity::where('subject_type', Finding::class)
            ->where('subject_id', $finding->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertTrue($activity->causer->is($this->engineer));
    }

    // ---- C / D — Decision create activity + actor ----

    public function test_c_decision_creation_produces_an_activity_log_entry(): void
    {
        $finding = $this->completeChecklistWithFinding();

        $this->actingAs($this->supervisor)
            ->postJson("/api/findings/{$finding->id}/decision", ['decision' => 'no_action'])
            ->assertOk();

        $this->assertTrue(
            Activity::where('subject_type', MaintenanceDecision::class)->where('event', 'created')->exists()
        );
    }

    public function test_d_decision_creation_activity_causer_is_the_supervisor(): void
    {
        $finding = $this->completeChecklistWithFinding();

        $this->actingAs($this->supervisor)
            ->postJson("/api/findings/{$finding->id}/decision", ['decision' => 'no_action'])
            ->assertOk();

        $decision = MaintenanceDecision::firstOrFail();
        $activity = Activity::where('subject_type', MaintenanceDecision::class)
            ->where('subject_id', $decision->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertTrue($activity->causer->is($this->supervisor));
    }

    // ---- E — Finding update activity (no public/admin update API exists) ----

    public function test_e_finding_update_is_logged_even_though_no_api_exposes_it(): void
    {
        $this->assertNoWriteRouteExists('findings', ['PUT', 'PATCH', 'DELETE']);

        $finding = $this->completeChecklistWithFinding();

        $this->actingAs($this->engineer);
        $finding->update(['description' => 'Koreksi deskripsi — diuji langsung lewat model.']);

        $activity = Activity::where('subject_type', Finding::class)
            ->where('subject_id', $finding->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity, 'LogsDefaultActivity should log a direct model update even with no API route for it.');
        $this->assertSame(
            'Koreksi deskripsi — diuji langsung lewat model.',
            $activity->attribute_changes['attributes']['description'] ?? null
        );
    }

    // ---- F — Decision update activity (no public/admin update API exists) ----

    public function test_f_decision_update_is_logged_even_though_no_api_exposes_it(): void
    {
        // Only POST .../decision (create) exists — no PUT/PATCH/DELETE on a
        // decision resource anywhere in routes/api.php.
        $this->assertNoWriteRouteExists('decision', ['PUT', 'PATCH', 'DELETE']);

        $finding = $this->completeChecklistWithFinding();
        $this->actingAs($this->supervisor)
            ->postJson("/api/findings/{$finding->id}/decision", ['decision' => 'no_action'])
            ->assertOk();

        $decision = MaintenanceDecision::firstOrFail();

        $this->actingAs($this->supervisor);
        $decision->update(['notes' => 'Catatan dikoreksi — diuji langsung lewat model.']);

        $activity = Activity::where('subject_type', MaintenanceDecision::class)
            ->where('subject_id', $decision->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity, 'LogsDefaultActivity should log a direct model update even with no API route for it.');
    }

    // ---- G — no duplicate audit on a single create ----

    public function test_g_a_single_finding_creation_produces_exactly_one_created_activity(): void
    {
        $finding = $this->completeChecklistWithFinding();

        $this->assertSame(
            1,
            Activity::where('subject_type', Finding::class)->where('subject_id', $finding->id)->where('event', 'created')->count()
        );
    }

    public function test_g_a_single_no_action_decision_creation_produces_exactly_one_created_activity(): void
    {
        $finding = $this->completeChecklistWithFinding();

        // NO_ACTION never calls a follow-up update() after create() — the
        // one path where "created" is the only write, so this is the
        // meaningful no-duplication check (a REPLACE_PART/MONITOR-with-
        // follow-up decision legitimately gets a second "updated" activity
        // once resulting_task_id/resulting_replacement_request_id is set —
        // that's a real, distinct fact, not a duplicate).
        $this->actingAs($this->supervisor)
            ->postJson("/api/findings/{$finding->id}/decision", ['decision' => 'no_action'])
            ->assertOk();

        $decision = MaintenanceDecision::firstOrFail();

        $this->assertSame(
            1,
            Activity::where('subject_type', MaintenanceDecision::class)->where('subject_id', $decision->id)->count()
        );
    }

    // ---- End-to-end: two distinct actors, both visible, correctly branch-scoped ----

    public function test_end_to_end_finding_and_decision_activities_show_two_distinct_actors(): void
    {
        $finding = $this->completeChecklistWithFinding();

        $this->actingAs($this->supervisor)
            ->postJson("/api/findings/{$finding->id}/decision", ['decision' => 'monitor'])
            ->assertOk();

        $findingActivity = Activity::where('subject_type', Finding::class)
            ->where('subject_id', $finding->id)->where('event', 'created')->firstOrFail();
        $decisionActivity = Activity::where('subject_type', MaintenanceDecision::class)
            ->where('event', 'created')->firstOrFail();

        $this->assertSame($this->engineer->id, $findingActivity->causer->id);
        $this->assertSame($this->supervisor->id, $decisionActivity->causer->id);
        $this->assertNotSame($findingActivity->causer->id, $decisionActivity->causer->id);

        // Both activities must resolve to this same branch — proves the
        // BranchScopeResolver fix for MaintenanceDecision (it previously
        // wasn't in the match list at all, so it always resolved to null
        // and silently vanished from the branch-scoped Activity Log).
        $resolver = app(BranchScopeResolver::class);
        $this->assertSame($this->branch->id, $resolver->resolve($findingActivity->subject));
        $this->assertSame($this->branch->id, $resolver->resolve($decisionActivity->subject));
    }

    /**
     * @param  list<string>  $methods
     */
    private function assertNoWriteRouteExists(string $uriNeedle, array $methods): void
    {
        $matches = collect(Route::getRoutes())->filter(function ($route) use ($uriNeedle, $methods) {
            return str_contains($route->uri(), $uriNeedle) && array_intersect($route->methods(), $methods);
        });

        $this->assertCount(
            0,
            $matches,
            "Expected no write route matching '{$uriNeedle}' for methods [".implode(',', $methods).'], found: '.
            $matches->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->implode(', ')
        );
    }
}
