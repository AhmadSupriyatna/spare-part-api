<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundational fix identified before any Budget "realization" reporting can
 * be trusted: part_stocks.unit_cost is a live weighted average that keeps
 * moving, so re-deriving a past month's actual spend from it later (after
 * prices have since changed) would be wrong. Every ledger row now snapshots
 * the unit_cost that was actually in effect for that specific transaction
 * (see StockMovementService::record()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('balance_after');
        });

        // Best-effort backfill for existing rows: there's no way to recover
        // the true historical price, so this uses each part_stock's current
        // unit_cost as an approximation rather than leaving old rows
        // unpriced. Acceptable here because the app has no production data
        // yet; a value this imprecise wouldn't be an acceptable backfill
        // once real price history matters.
        DB::statement(<<<'SQL'
            UPDATE stock_ledger
            SET unit_cost = part_stocks.unit_cost
            FROM part_stocks
            WHERE stock_ledger.part_stock_id = part_stocks.id
              AND stock_ledger.unit_cost IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
