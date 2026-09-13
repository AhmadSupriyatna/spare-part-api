<?php

namespace Database\Seeders;

use App\Enums\UserRole;
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

        $superadmin = User::factory()->create([
            'name' => 'Superadmin',
            'email' => 'superadmin@spareparts.test',
        ]);
        $superadmin->assignRole(UserRole::Superadmin->value);
    }
}
