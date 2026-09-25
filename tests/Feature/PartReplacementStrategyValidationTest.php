<?php

namespace Tests\Feature;

use App\Enums\PartReplacementStrategy;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Part;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3B Step 2 — PartReplacementStrategy shrank from 6 cases to 3
 * (LifeBased/Scheduled/FailureBased) and, since there's no safe catch-all
 * left, a new Part must explicitly choose one instead of silently
 * inheriting a default (the old default was OnDemand, now removed). This
 * only covers that validation boundary, not the enum's downstream
 * behavior (already covered by BreakdownEventTypeTest, PartUnit tests,
 * Budget tests).
 */
class PartReplacementStrategyValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminSparePart;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();
        Unit::create(['name' => 'pcs']);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        $this->adminSparePart = User::factory()->create();
        $this->adminSparePart->assignRole(UserRole::AdminSparePart->value);
        $this->adminSparePart->branches()->attach($branch->id);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'item_master_no' => 'SP-'.fake()->unique()->numerify('####'),
            'name' => 'Test Part',
            'unit' => 'pcs',
        ], $overrides);
    }

    /** The exact regression this step exists for: omitting replacement_strategy must be rejected, not silently defaulted. */
    public function test_creating_a_part_without_replacement_strategy_is_rejected(): void
    {
        $response = $this->actingAs($this->adminSparePart)->postJson('/api/parts', $this->payload());

        $response->assertUnprocessable()->assertJsonValidationErrors('replacement_strategy');
        $this->assertSame(0, Part::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validStrategyProvider(): array
    {
        return [
            'life_based' => [PartReplacementStrategy::LifeBased->value],
            'scheduled' => [PartReplacementStrategy::Scheduled->value],
            'failure_based' => [PartReplacementStrategy::FailureBased->value],
        ];
    }

    #[DataProvider('validStrategyProvider')]
    public function test_creating_a_part_with_a_valid_strategy_succeeds(string $strategy): void
    {
        $response = $this->actingAs($this->adminSparePart)->postJson('/api/parts', $this->payload([
            'replacement_strategy' => $strategy,
        ]));

        $response->assertCreated();
        $this->assertSame($strategy, $response->json('data.replacement_strategy'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removedStrategyProvider(): array
    {
        return [
            'condition_based' => ['condition_based'],
            'inspection_based' => ['inspection_based'],
            'on_demand' => ['on_demand'],
            'nonsense_value' => ['not_a_real_strategy'],
        ];
    }

    #[DataProvider('removedStrategyProvider')]
    public function test_creating_a_part_with_a_removed_or_invalid_strategy_is_rejected(string $strategy): void
    {
        $response = $this->actingAs($this->adminSparePart)->postJson('/api/parts', $this->payload([
            'replacement_strategy' => $strategy,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('replacement_strategy');
        $this->assertSame(0, Part::count());
    }
}
