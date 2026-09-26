<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Equipment;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\ProductionLine;
use App\Models\Task;
use App\Models\User;
use App\Services\PartLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Inventory Workspace's Kritis/Peringatan/Normal badge — redefined away from
 * minimum_stock/reorder_point (still used, unchanged, by StockAlertService's
 * separate reorder-request automation) to two comparisons that actually
 * answer "do we have enough spares": Kritis when available stock can't even
 * cover what's already committed to open WOs, Peringatan when it couldn't
 * cover every currently-installed unit failing at once.
 */
class PartStockStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Equipment $equipment;

    private Part $part;

    private PartStock $stock;

    private User $admin;

    private User $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $line = ProductionLine::factory()->create(['branch_id' => $this->branch->id]);
        $machine = Machine::factory()->create(['line_id' => $line->id]);
        $this->equipment = Equipment::factory()->create(['machine_id' => $machine->id]);
        $this->part = Part::factory()->create();
        $this->stock = PartStock::factory()->create([
            'part_id' => $this->part->id,
            'branch_id' => $this->branch->id,
            'quantity_on_hand' => 5,
            'minimum_stock' => 0,
            'reorder_point' => 0,
        ]);

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::AdminSparePart->value);
        $this->admin->branches()->attach($this->branch->id);
        $this->installer = User::factory()->create();
    }

    private function fetchStock(): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/branches/{$this->branch->id}/part-stocks")
            ->json('data.0');
    }

    public function test_status_is_normal_when_no_reservation_or_installation_pressure(): void
    {
        $data = $this->fetchStock();

        $this->assertFalse($data['is_critical']);
        $this->assertFalse($data['is_warning']);
        $this->assertSame(5, $data['available_quantity']);
    }

    public function test_is_critical_when_available_stock_cannot_cover_reserved_quantity(): void
    {
        Task::factory()->create([
            'equipment_id' => $this->equipment->id,
            'status' => TaskStatus::Pending,
            'part_stock_id' => $this->stock->id,
            'quantity_used' => 10, // more than the 5 on hand
        ]);

        $data = $this->fetchStock();

        $this->assertTrue($data['is_critical']);
        $this->assertFalse($data['is_warning']);
        $this->assertSame(0, $data['available_quantity']);
    }

    public function test_is_warning_when_available_stock_is_below_active_installation_count(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        // 6 active installations, but only 5 on hand and nothing reserved.
        for ($i = 0; $i < 6; $i++) {
            $equipment = Equipment::factory()->create(['machine_id' => $this->equipment->machine_id]);
            $lifecycle->install($equipment, ['part_id' => $this->part->id], $this->installer);
        }

        $data = $this->fetchStock();

        $this->assertFalse($data['is_critical']);
        $this->assertTrue($data['is_warning']);
        $this->assertSame(6, $data['active_installation_count']);
    }

    public function test_normal_when_available_stock_covers_installations_and_reservations(): void
    {
        $lifecycle = app(PartLifecycleService::class);
        $lifecycle->install($this->equipment, ['part_id' => $this->part->id], $this->installer);

        $data = $this->fetchStock();

        $this->assertFalse($data['is_critical']);
        $this->assertFalse($data['is_warning']);
        $this->assertSame(1, $data['active_installation_count']);
    }

    public function test_minimum_stock_and_reorder_point_no_longer_drive_the_badge(): void
    {
        // On hand is far below both thresholds, but nothing is reserved or installed.
        $this->stock->update(['minimum_stock' => 100, 'reorder_point' => 50]);

        $data = $this->fetchStock();

        $this->assertFalse($data['is_critical']);
        $this->assertFalse($data['is_warning']);
    }
}
