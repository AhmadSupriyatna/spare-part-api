<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Mesin Luar Line" — mirrors the Equipment "Asset Outside Line" pattern
 * (see 2026_09_25_074003_add_branch_id_and_nullable_machine_id_to_equipment_table)
 * one level up: a Machine no longer has to belong to a production Line.
 * Exactly one of line_id (production) or branch_id (a standalone asset like
 * a compressor room or genset, with no production Line) is set, enforced in
 * Machine::booted() rather than a DB CHECK constraint, for the same
 * Postgres/SQLite-parity reason as Equipment.
 *
 * unique(['branch_id', 'code']) mirrors the existing
 * unique(['line_id', 'code']) — NULL is distinct in a unique index on both
 * drivers, so neither constraint interferes with rows that don't use that
 * particular column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('line_id')->nullable()->change();
            $table->foreignId('branch_id')->nullable()->after('line_id')->constrained('branches')->nullOnDelete();
            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'code']);
            $table->dropConstrainedForeignId('branch_id');
            $table->foreignId('line_id')->nullable(false)->change();
        });
    }
};
