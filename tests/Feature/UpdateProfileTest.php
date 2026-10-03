<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Self-service "Edit Profil" (PUT /user) — distinct from the superadmin-only
 * UserController::update(). Any authenticated user can rename themselves
 * freely, but changing their OWN password requires re-entering the current
 * one first (current_password rule), unlike an admin editing someone else.
 */
class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_change_their_own_name(): void
    {
        $user = User::factory()->create(['name' => 'Nama Lama']);

        $response = $this->actingAs($user)->putJson('/api/user', ['name' => 'Nama Baru']);

        $response->assertOk()->assertJsonPath('name', 'Nama Baru');
        $this->assertSame('Nama Baru', $user->fresh()->name);
    }

    public function test_changing_password_requires_the_current_password(): void
    {
        $user = User::factory()->create(); // factory default password: 'password'

        $response = $this->actingAs($user)->putJson('/api/user', [
            'password' => 'brand-new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_changing_password_with_the_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/user', [
            'current_password' => 'totally-wrong',
            'password' => 'brand-new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_changing_password_with_the_correct_current_password_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/user', [
            'current_password' => 'password',
            'password' => 'brand-new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_updating_only_the_name_never_requires_a_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/user', ['name' => 'Cuma Nama']);

        $response->assertOk();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_user_can_change_their_own_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/api/user', [
            'avatar' => UploadedFile::fake()->image('foto.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($user->fresh()->avatar_path);
        $this->assertNotNull($response->json('avatar_url'));
    }
}
