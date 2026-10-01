<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * budget_items used to only ever hold LifeBased rows, each with its own
 * estimated_lifetime_percent/price_increase_percent — both now live once
 * per Budget instead (see add_global_forecast_percents_to_budgets_table).
 * This generalizes the table to also hold FailureBased and Scheduled rows,
 * each needing a different per-part input:
 *   - failure_based: estimated_failure_count_per_year (a manual guess,
 *     since failure rates genuinely vary per part type).
 *   - scheduled: replacement_probability_percent, multiplied against how
 *     often Task Library actually schedules an inspection covering that
 *     part (see BudgetForecastService::inspectionsPerYearFor()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->dropColumn(['estimated_lifetime_percent', 'price_increase_percent']);
            $table->decimal('estimated_failure_count_per_year', 8, 2)->nullable()->after('part_id');
            $table->decimal('replacement_probability_percent', 6, 2)->nullable()->after('estimated_failure_count_per_year');
        });
    }

    public function down(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->dropColumn(['estimated_failure_count_per_year', 'replacement_probability_percent']);
            $table->decimal('estimated_lifetime_percent', 6, 2)->default(0)->after('part_id');
            $table->decimal('price_increase_percent', 6, 2)->default(0)->after('estimated_lifetime_percent');
        });
    }
};
