<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The FP3 "Departemen" picklist, managed in Settings — same shape as Unit. */
class DepartmentTest extends TestCase
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

    public function test_departments_can_be_listed_without_authentication(): void
    {
        Department::create(['name' => 'Produksi']);

        $this->getJson('/api/public/departments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_create_a_department(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/departments', ['name' => 'QA']);

        $response->assertCreated();
        $this->assertDatabaseHas('departments', ['name' => 'QA']);
    }

    public function test_department_names_must_be_unique(): void
    {
        Department::create(['name' => 'QA']);

        $this->actingAs($this->admin)->postJson('/api/departments', ['name' => 'QA'])->assertUnprocessable();
    }

    public function test_admin_can_update_and_delete_a_department(): void
    {
        $department = Department::create(['name' => 'QA']);

        $this->actingAs($this->admin)
            ->putJson("/api/departments/{$department->id}", ['name' => 'Quality Assurance'])
            ->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Quality Assurance']);

        $this->actingAs($this->admin)->deleteJson("/api/departments/{$department->id}")->assertNoContent();
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_a_non_admin_cannot_create_a_department(): void
    {
        $engineer = User::factory()->create();
        $engineer->assignRole(UserRole::Engineer->value);

        $this->actingAs($engineer)->postJson('/api/departments', ['name' => 'QA'])->assertForbidden();
    }
}
