<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the Spatie role row in place (same id, so every existing
 * model_has_roles assignment carries over automatically) rather than
 * creating a new "admin_spare_part" role and leaving "admin_gudang"
 * orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('name', 'admin_gudang')->update(['name' => 'admin_spare_part']);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'admin_spare_part')->update(['name' => 'admin_gudang']);
    }
};
