<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simplified away along with `action` — the checklist is now just "which
 * installed parts does this recipe touch", nothing per-part beyond that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->dropColumn('needs_replacement');
        });
    }

    public function down(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->boolean('needs_replacement')->default(false)->after('part_id');
        });
    }
};
