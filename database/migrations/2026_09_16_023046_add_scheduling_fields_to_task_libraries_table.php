<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the metadata an "Automation PM Schedule" pass will eventually read to
 * suggest due dates — this migration only stores it, nothing generates a
 * Task from it yet (that stays a manual step via PmSchedulingService).
 * `code` backfills existing rows before the unique index goes on, so it has
 * to run as two Schema::table calls rather than one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_libraries', function (Blueprint $table) {
            $table->string('code')->nullable()->after('id');
            $table->string('schedule_type')->nullable()->after('description');
            $table->unsignedInteger('interval_days')->nullable()->after('schedule_type');
            $table->unsignedInteger('interval_hours')->nullable()->after('interval_days');
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('interval_hours');
        });

        DB::table('task_libraries')->whereNull('code')->orderBy('id')->each(function ($row) {
            DB::table('task_libraries')
                ->where('id', $row->id)
                ->update(['code' => 'TL-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT)]);
        });

        Schema::table('task_libraries', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('task_libraries', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'schedule_type', 'interval_days', 'interval_hours', 'estimated_duration_minutes']);
        });
    }
};
