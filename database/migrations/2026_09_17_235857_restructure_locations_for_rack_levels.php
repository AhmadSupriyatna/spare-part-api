<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `locations` (each row = one Bin) moves from two free-text strings
 * (`rack`, `bin`, both manually typed, `code` also manually typed) to a
 * proper `rack_level_id` FK + auto-incrementing `bin_number` — `code`
 * stays as a real column but is now always server-generated
 * ("{rack.label}{level_number}-B{bin_number}", e.g. "A1-B1").
 *
 * Existing rows are test/seed data the user confirmed is fine to clear —
 * PartStock.location_id already nulls out on delete, so this is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('locations')->delete();

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'code']);
            $table->dropColumn(['rack', 'bin']);
            $table->foreignId('rack_level_id')->after('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('bin_number')->after('rack_level_id');

            $table->unique(['rack_level_id', 'bin_number']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['rack_level_id', 'bin_number']);
            $table->dropConstrainedForeignId('rack_level_id');
            $table->dropColumn('bin_number');
            $table->string('rack')->after('code');
            $table->string('bin')->after('rack');
            $table->unique(['branch_id', 'code']);
        });
    }
};
