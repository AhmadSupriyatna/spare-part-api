<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Whether "Input kWh" (per-Line, per-week) asks for a cumulative
            // meter reading (like Jam Operasional) or the week's usage value
            // directly — a global switch in Pengaturan, not per-request, so
            // every LineKwhLog row stays consistent with how it was entered.
            $table->string('kwh_input_mode')->default('direct')->after('avg_weekly_operating_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('kwh_input_mode');
        });
    }
};
