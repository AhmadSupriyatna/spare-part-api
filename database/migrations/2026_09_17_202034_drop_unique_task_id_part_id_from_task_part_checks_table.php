<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task can now have several checklist rows for the same part_id — one
 * per planned unit/address ("satu baris satu alamat", see
 * PmSchedulingService::schedule()) instead of one row holding a combined
 * quantity_planned. The (task_id, part_id) unique constraint from the
 * original one-row-per-part design directly blocks that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->dropUnique(['task_id', 'part_id']);
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->dropIndex(['task_id']);
            $table->unique(['task_id', 'part_id']);
        });
    }
};
