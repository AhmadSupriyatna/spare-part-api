<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Estimasi Life Time (%)" and "Estimasi Kenaikan Harga (%)" move from a
 * per-part input (budget_items, one row per part) to one global assumption
 * per Budget — set once, applied to every part instead of tuned "1
 * persatu". global_lifetime_percent only means anything for a life_based
 * Budget; global_price_increase_percent applies across all 3 strategies
 * (price inflation isn't a function of replacement strategy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->decimal('global_lifetime_percent', 6, 2)->nullable()->after('replacement_strategy');
            $table->decimal('global_price_increase_percent', 6, 2)->default(0)->after('global_lifetime_percent');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['global_lifetime_percent', 'global_price_increase_percent']);
        });
    }
};
