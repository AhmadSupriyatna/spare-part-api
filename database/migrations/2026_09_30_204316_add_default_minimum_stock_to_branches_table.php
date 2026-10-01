<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Standar Minimum Plant" — this branch's own default minimum-stock
     * number, used as a Part's effective minimum_stock whenever its
     * minimum_stock_strategy is 'standard' (see
     * PartStockController::attachComputedAttributes()). Per-plant rather
     * than a single company-wide number, since different plants can run
     * very different scales of operation.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('default_minimum_stock')->default(0)->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('default_minimum_stock');
        });
    }
};
