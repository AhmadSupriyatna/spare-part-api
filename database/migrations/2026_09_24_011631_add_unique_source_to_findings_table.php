<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Step 3 final hardening: one source record (task_part_check or
 * part_replacement_request) can have at most one Finding — the database is
 * now the source of truth for that invariant, not just "one completion
 * request only creates one Finding per checklist row" (which the Step 3
 * report already proved, but that alone doesn't stop a separate/retried
 * completion request from creating a second Finding for the same source).
 *
 * Replaces the plain (source_type, source_id) index from the original
 * findings migration with a unique one — a plain non-unique index there
 * alongside a new unique one would just be a redundant duplicate, since a
 * unique index already serves lookups just as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });
    }
};
