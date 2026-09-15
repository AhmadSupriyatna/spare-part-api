<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(UnitSeeder::class);

        // Users are created before the demo data so the reactive tasks it
        // seeds (see below) have someone real to land on - a fresh seed
        // otherwise leaves every task unassigned and "Tugas Saya" empty for
        // both accounts.
        $superadmin = User::factory()->create([
            'name' => 'Superadmin',
            'email' => 'superadmin@spareparts.test',
        ]);
        $superadmin->assignRole(UserRole::Superadmin->value);

        $teknisi = User::factory()->create([
            'name' => 'Teknisi Demo',
            'email' => 'teknisi@spareparts.test',
        ]);
        $teknisi->assignRole(UserRole::Teknisi->value);

        // Admin Spare Part and Engineer are deliberately scoped to a single
        // branch below (not all of them, unlike Superadmin/Teknisi/
        // Supervisor) so the branch.access restriction on those roles is
        // actually exercised by a fresh seed, not just true by omission.
        $adminSparePart = User::factory()->create([
            'name' => 'Admin Spare Part Demo',
            'email' => 'admin.sparepart@spareparts.test',
        ]);
        $adminSparePart->assignRole(UserRole::AdminSparePart->value);

        $supervisor = User::factory()->create([
            'name' => 'Supervisor Demo',
            'email' => 'supervisor@spareparts.test',
        ]);
        $supervisor->assignRole(UserRole::Supervisor->value);

        $engineer = User::factory()->create([
            'name' => 'Engineer Demo',
            'email' => 'engineer@spareparts.test',
        ]);
        $engineer->assignRole(UserRole::Engineer->value);

        $this->call(DemoDataSeeder::class);

        $superadmin->branches()->attach(Branch::all());
        $teknisi->branches()->attach(Branch::all());
        $supervisor->branches()->attach(Branch::all());
        $adminSparePart->branches()->attach(Branch::first());
        $engineer->branches()->attach(Branch::first());

        // DemoDataSeeder's reactive/unscheduled tasks are created with no
        // assignee; hand them to the demo technician so their worklist
        // ("Tugas Saya") has something in it out of the box.
        Task::whereNull('assigned_to')->update(['assigned_to' => $teknisi->id]);

        // Runs after branches are attached and equipment/parts exist, since it
        // populates BOM, Task Library/PM schedules, part-unit repair cycles,
        // and breakdown/part-unit-action requests on top of that base data.
        $this->call(LifecycleDemoSeeder::class);
    }
}
