<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dropped almost as soon as it was added: what to actually do with a part
 * (inspect/lubricate/etc.) turned out to belong to the future PM Schedule
 * step, not the recipe itself — `needs_replacement` stays, since that one
 * is a property of the recipe ("this part typically needs replacing here").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->dropColumn('action');
        });
    }

    public function down(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->string('action')->default('inspection')->after('part_id');
        });
    }
};
