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

        $this->call(DemoDataSeeder::class);

        $superadmin->branches()->attach(Branch::all());
        $teknisi->branches()->attach(Branch::all());

        // DemoDataSeeder's reactive/unscheduled tasks are created with no
        // assignee; hand them to the demo technician so their worklist
        // ("Tugas Saya") has something in it out of the box.
        Task::whereNull('assigned_to')->update(['assigned_to' => $teknisi->id]);
    }
}
