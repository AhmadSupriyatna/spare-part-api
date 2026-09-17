<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * The "Teknisi" role predates "Engineer" and never carried any distinct
 * permission of its own (see app/Http/Middleware/EnsureBranchAccess.php's
 * per-branch-restricted role list and every `role:` route gate — Teknisi
 * never appears in either). The user's confirmed 4-level model is Super
 * Admin / Supervisor-Manager / Admin Spare Part / Engineer, so every
 * Teknisi-role user becomes Engineer instead and the Teknisi role row is
 * removed. Not reversible in a way that restores who had Teknisi — down()
 * only recreates the empty role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $teknisi = Role::where('name', 'teknisi')->where('guard_name', 'web')->first();

        if (! $teknisi) {
            return;
        }

        $engineer = Role::findOrCreate(UserRole::Engineer->value, 'web');

        foreach ($teknisi->users as $user) {
            $user->assignRole($engineer);
            $user->removeRole($teknisi);
        }

        $teknisi->delete();
    }

    public function down(): void
    {
        Role::findOrCreate('teknisi', 'web');
    }
};
