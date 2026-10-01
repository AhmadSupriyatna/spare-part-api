<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes "Pemesanan Ulang" (Reorder) and its silent StockAlertService
 * automation entirely — unused: reorder_requests had no dashboard/report
 * consumer beyond its own now-deleted page, and stock_alerts never had a
 * UI at all (it only existed to decide when to spawn a reorder request).
 * minimum_stock/reorder_point/reorder_quantity also drop from part_stocks
 * — minimum_stock becomes a computed value (Part's minimum_stock_strategy
 * x Branch's default_minimum_stock or active installation count, see
 * PartStockController::attachComputedAttributes()) rather than a manually
 * entered column nobody had a way to actually set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reorder_requests');
        Schema::dropIfExists('stock_alerts');

        Schema::table('part_stocks', function (Blueprint $table) {
            $table->dropColumn(['minimum_stock', 'reorder_point', 'reorder_quantity']);
        });
    }

    public function down(): void
    {
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->unsignedInteger('reorder_quantity')->default(0);
        });

        // reorder_requests/stock_alerts are not recreated — this migration
        // is a one-way removal of the feature; a rollback here only
        // restores the part_stocks columns so other tests/migrations that
        // touch them keep working.
    }
};
