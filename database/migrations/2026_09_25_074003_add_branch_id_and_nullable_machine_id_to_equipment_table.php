<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B "Asset Outside Line" — an Equipment no longer has to belong to a
 * production Line → Machine chain. Two mutually exclusive ways to reach a
 * branch now exist on this one table (enforced in the application layer,
 * see Equipment::booted(), not a DB CHECK constraint — this project has no
 * precedent for those and this keeps SQLite/Postgres parity for tests):
 *
 *   machine_id populated, branch_id null  -> production equipment
 *     (existing rows, completely unchanged by this migration)
 *   machine_id null, branch_id populated  -> outside-line asset (new)
 *
 * unique(['branch_id', 'code']) mirrors the existing
 * unique(['machine_id', 'code']) — Postgres/SQLite both treat NULL as
 * distinct in a unique index, so neither constraint interferes with rows
 * that don't use that particular column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->foreignId('machine_id')->nullable()->change();
            $table->foreignId('branch_id')->nullable()->after('machine_id')->constrained('branches')->nullOnDelete();
            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'code']);
            $table->dropConstrainedForeignId('branch_id');
            $table->foreignId('machine_id')->nullable(false)->change();
        });
    }
};
