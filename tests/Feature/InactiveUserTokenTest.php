<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InactiveUserTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::Superadmin->value, 'web');
    }

    public function test_deactivating_a_user_revokes_all_existing_tokens(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(UserRole::Superadmin->value);
        $user = User::factory()->create(['is_active' => true]);
        $firstToken = $user->createToken('first-device')->plainTextToken;
        $user->createToken('second-device');

        $this->actingAs($superadmin)
            ->postJson("/api/users/{$user->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_inactive_users_are_rejected_even_if_they_have_a_token(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $token = $user->createToken('inactive-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun ini telah dinonaktifkan.');
    }
}
