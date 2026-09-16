<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feeds the auto-scheduling date calculation for Part Lifetime-triggered PM
 * tasks: remaining_hours / avg_weekly_operating_hours = weeks until due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('avg_weekly_operating_hours')->default(85)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('avg_weekly_operating_hours');
        });
    }
};
