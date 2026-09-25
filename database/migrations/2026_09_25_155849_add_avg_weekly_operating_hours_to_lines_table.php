<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Line override of CompanySetting.avg_weekly_operating_hours (used by
 * PartLifetimeService's Life Based due-date projection and
 * BudgetForecastService's pcs/year estimate) — a plant's lines don't
 * necessarily all run the same average hours per week, so this was moved
 * from a single company-wide number to one every Line can set individually.
 * Nullable: a Line with no override falls back to the company setting (see
 * ProductionLine::effectiveAvgWeeklyOperatingHours()), so existing data
 * needs no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('avg_weekly_operating_hours')->nullable()->after('runtime_hours');
        });
    }

    public function down(): void
    {
        Schema::table('lines', function (Blueprint $table) {
            $table->dropColumn('avg_weekly_operating_hours');
        });
    }
};
