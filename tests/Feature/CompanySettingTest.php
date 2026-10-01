<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Nama Aplikasi" + its logo — the app's own in-product branding, editable
 * by Superadmin only, deliberately separate from "Nama Perusahaan" (the
 * printed-paperwork identity), which Admin Spare Part can still edit. See
 * CompanySettingController::update()'s own docblock.
 */
class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        // Pre-create the singleton row so the requests below see an
        // already-existing settings row (200), not a first-ever
        // firstOrCreate() inside the controller itself (which Laravel's
        // JsonResource reports as 201 via wasRecentlyCreated) — matching
        // real usage, where this row already exists in a live app.
        CompanySetting::current();
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_superadmin_can_set_app_name(): void
    {
        $superadmin = $this->actingAsRole(UserRole::Superadmin);

        $response = $this->actingAs($superadmin)->putJson('/api/settings/company', [
            'name' => 'PT Sumber Makmur Sejahtera',
            'app_name' => 'Ruang Kontrol Maintenance',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.app_name', 'Ruang Kontrol Maintenance');
        $this->assertSame('Ruang Kontrol Maintenance', CompanySetting::current()->app_name);
    }

    public function test_admin_spare_part_cannot_set_app_name_but_can_still_update_the_rest(): void
    {
        $admin = $this->actingAsRole(UserRole::AdminSparePart);

        $response = $this->actingAs($admin)->putJson('/api/settings/company', [
            'name' => 'PT Sumber Makmur Sejahtera',
            'app_name' => 'Nama Yang Dicoba Diselundupkan',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'PT Sumber Makmur Sejahtera');
        $this->assertNotSame('Nama Yang Dicoba Diselundupkan', CompanySetting::current()->app_name);
    }

    public function test_app_name_falls_back_to_the_default_when_never_set(): void
    {
        $admin = $this->actingAsRole(UserRole::AdminSparePart);

        $response = $this->actingAs($admin)->getJson('/api/settings/company');

        $response->assertOk();
        $response->assertJsonPath('data.app_name', 'Sistem Manajemen Spare Part');
    }

    public function test_admin_spare_part_can_switch_the_kwh_input_mode(): void
    {
        $admin = $this->actingAsRole(UserRole::AdminSparePart);

        $response = $this->actingAs($admin)->putJson('/api/settings/company', [
            'name' => 'PT Sumber Makmur Sejahtera',
            'kwh_input_mode' => 'reading',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.kwh_input_mode', 'reading');
        $this->assertSame('reading', CompanySetting::current()->kwh_input_mode);
    }
}
