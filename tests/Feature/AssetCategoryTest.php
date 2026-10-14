<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\ProductionLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole(UserRole::Superadmin->value);
    }

    public function test_asset_categories_require_authentication_to_list(): void
    {
        $this->getJson('/api/asset-categories')->assertUnauthorized();

        $this->actingAs($this->superadmin)->getJson('/api/asset-categories')->assertOk();
    }

    public function test_superadmin_can_create_and_update_categories(): void
    {
        $response = $this->actingAs($this->superadmin)->postJson('/api/asset-categories', [
            'name' => 'Pump',
        ]);

        $response->assertCreated();
        $categoryId = $response->json('data.id');

        $this->putJson("/api/asset-categories/{$categoryId}", ['name' => 'Water Pump'])->assertOk();
        $this->assertDatabaseHas('asset_categories', [
            'id' => $categoryId,
            'type' => 'equipment',
            'name' => 'Water Pump',
        ]);
    }

    public function test_machines_under_lines_have_no_category(): void
    {
        $branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($this->superadmin)
            ->postJson("/api/lines/{$line->id}/machines", ['name' => 'Filling Machine'])
            ->assertCreated()
            ->assertJsonMissingPath('data.category');
    }

    public function test_outside_line_machines_have_no_category(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAs($this->superadmin)
            ->postJson("/api/branches/{$branch->id}/machines", ['name' => 'Compressor Room'])
            ->assertCreated()
            ->assertJsonMissingPath('data.category');
    }

    public function test_renaming_an_equipment_category_updates_existing_equipment(): void
    {
        $category = AssetCategory::create(['type' => 'equipment', 'name' => 'Pump']);
        $equipment = Equipment::factory()->create(['category' => 'Pump']);

        $this->actingAs($this->superadmin)
            ->putJson("/api/asset-categories/{$category->id}", ['name' => 'Water Pump'])
            ->assertOk();

        $this->assertDatabaseHas('equipment', ['id' => $equipment->id, 'category' => 'Water Pump']);
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $category = AssetCategory::create(['type' => 'equipment', 'name' => 'Pump']);
        Equipment::factory()->create(['category' => 'Pump']);

        $this->actingAs($this->superadmin)
            ->deleteJson("/api/asset-categories/{$category->id}")
            ->assertConflict();

        $this->assertDatabaseHas('asset_categories', ['id' => $category->id]);
    }

    public function test_non_superadmins_cannot_manage_categories(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::AdminSparePart->value);

        $this->actingAs($admin)
            ->postJson('/api/asset-categories', ['name' => 'Pump'])
            ->assertForbidden();
    }
}
