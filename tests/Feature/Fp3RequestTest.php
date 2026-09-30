<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Fp3Request;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FP3 (Formulir Permintaan Perbaikan dan Pembuatan) — public "Ajukan FP3"
 * submission (no login, no equipment/machine/line at all) through to the
 * Engineer's report via Workspace, which immediately issues any spare parts
 * used out of stock (no separate approval step, unlike Breakdown — see
 * Fp3RequestService::complete()'s own docblock).
 */
class Fp3RequestTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $engineer;

    private User $otherEngineer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->branch = Branch::factory()->create();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->engineer = User::factory()->create();
        $this->engineer->assignRole(UserRole::Engineer->value);
        $this->engineer->branches()->attach($this->branch->id);

        $this->otherEngineer = User::factory()->create();
        $this->otherEngineer->assignRole(UserRole::Engineer->value);
        $this->otherEngineer->branches()->attach($this->branch->id);
    }

    // --- Public submission ---

    public function test_a_request_can_be_submitted_without_authentication(): void
    {
        $response = $this->postJson("/api/public/branches/{$this->branch->id}/fp3-requests", [
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah di area A',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame('Budi', $response->json('data.requester_name'));
        $this->assertNotNull($response->json('data.request_photo_url'));
    }

    public function test_submission_requires_a_photo(): void
    {
        $this->postJson("/api/public/branches/{$this->branch->id}/fp3-requests", [
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah',
        ])->assertUnprocessable();
    }

    public function test_each_request_gets_a_sequential_code_per_branch(): void
    {
        $payload = fn () => [
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
        ];

        $first = $this->postJson("/api/public/branches/{$this->branch->id}/fp3-requests", $payload());
        $second = $this->postJson("/api/public/branches/{$this->branch->id}/fp3-requests", $payload());

        $this->assertStringStartsWith('1/FP3/', $first->json('data.code'));
        $this->assertStringStartsWith('2/FP3/', $second->json('data.code'));
    }

    // --- Receive / claim ---

    private function makeRequest(): Fp3Request
    {
        return Fp3Request::create([
            'branch_id' => $this->branch->id,
            'code' => Fp3Request::generateCode($this->branch->id),
            'requester_name' => 'Budi',
            'department' => 'Produksi',
            'description' => 'Rak besi patah',
            'request_photo_path' => 'fp3/fake.webp',
        ]);
    }

    public function test_receiving_claims_the_request_and_moves_it_to_in_progress(): void
    {
        $fp3 = $this->makeRequest();

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/receive", []);

        $response->assertOk();
        $this->assertSame('in_progress', $response->json('data.status'));
        $this->assertNotNull($response->json('data.received_at'));
        $this->assertSame($this->engineer->id, $fp3->fresh()->received_by);
    }

    public function test_a_second_engineer_cannot_act_on_an_already_claimed_request(): void
    {
        $fp3 = $this->makeRequest();
        $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/receive", [])->assertOk();

        $this->actingAs($this->otherEngineer)
            ->postJson("/api/fp3-requests/{$fp3->id}/receive", [])
            ->assertForbidden();
    }

    public function test_receiving_can_set_an_optional_due_date_to_schedule_it_for_later(): void
    {
        $fp3 = $this->makeRequest();

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/receive", [
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.due_date'));
    }

    // --- Complete ---

    public function test_completing_directly_without_receiving_first_still_works(): void
    {
        $fp3 = $this->makeRequest();

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/complete", [
            'work_description' => 'Sudah dilas ulang',
            'executor_names' => 'Budi, Anto',
            'area_condition_after' => 'Bersih dan rapi',
            'disposition' => 'closed',
        ]);

        $response->assertOk();
        $this->assertSame('completed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.received_at'));
        $this->assertNotNull($response->json('data.completed_at'));
        $this->assertSame('closed', $response->json('data.disposition'));
    }

    public function test_completing_with_parts_used_issues_stock_immediately(): void
    {
        $fp3 = $this->makeRequest();
        $part = Part::factory()->create();
        $stock = PartStock::factory()->create([
            'part_id' => $part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 10,
        ]);

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/complete", [
            'work_description' => 'Ganti bracket',
            'executor_names' => 'Budi',
            'area_condition_after' => 'Aman',
            'disposition' => 'closed',
            'parts' => [
                ['part_id' => $part->id, 'quantity' => 3, 'notes' => 'Bracket baru'],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.part_usages'));
        $this->assertSame(7, $stock->fresh()->quantity_on_hand);
    }

    public function test_completing_with_a_part_that_has_no_stock_record_in_branch_is_rejected(): void
    {
        $fp3 = $this->makeRequest();
        $part = Part::factory()->create();

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/complete", [
            'work_description' => 'Ganti bracket',
            'executor_names' => 'Budi',
            'area_condition_after' => 'Aman',
            'disposition' => 'closed',
            'parts' => [
                ['part_id' => $part->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_completing_an_already_completed_request_is_rejected(): void
    {
        $fp3 = $this->makeRequest();
        $payload = [
            'work_description' => 'Selesai',
            'executor_names' => 'Budi',
            'area_condition_after' => 'Aman',
            'disposition' => 'closed',
        ];
        $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/complete", $payload)->assertOk();

        $this->actingAs($this->engineer)
            ->postJson("/api/fp3-requests/{$fp3->id}/complete", $payload)
            ->assertStatus(409);
    }

    // --- Cancel ---

    public function test_cancelling_marks_the_request_cancelled(): void
    {
        $fp3 = $this->makeRequest();

        $response = $this->actingAs($this->engineer)->postJson("/api/fp3-requests/{$fp3->id}/cancel", [
            'notes' => 'Duplikat permintaan',
        ]);

        $response->assertOk();
        $this->assertSame('cancelled', $response->json('data.status'));
    }

    // --- Branch scope ---

    public function test_index_for_branch_lists_only_that_branchs_requests(): void
    {
        $otherBranch = Branch::factory()->create();
        Fp3Request::create([
            'branch_id' => $this->branch->id,
            'code' => Fp3Request::generateCode($this->branch->id),
            'requester_name' => 'Mine',
            'department' => 'Produksi',
            'description' => 'x',
            'request_photo_path' => 'fp3/fake.webp',
        ]);
        Fp3Request::create([
            'branch_id' => $otherBranch->id,
            'code' => Fp3Request::generateCode($otherBranch->id),
            'requester_name' => 'Not Mine',
            'department' => 'Produksi',
            'description' => 'x',
            'request_photo_path' => 'fp3/fake.webp',
        ]);

        $response = $this->actingAs($this->engineer)->getJson("/api/branches/{$this->branch->id}/fp3-requests");

        $names = collect($response->json('data'))->pluck('requester_name')->all();
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Not Mine', $names);
    }
}
