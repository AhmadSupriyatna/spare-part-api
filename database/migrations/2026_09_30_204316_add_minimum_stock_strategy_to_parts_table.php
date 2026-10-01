<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            // Unlike replacement_strategy, this has a safe default: most
            // parts just follow the plant's general policy ("standard"),
            // only ones where full spare coverage per installed unit
            // matters need "installed" explicitly chosen.
            $table->string('minimum_stock_strategy')->default('standard')->after('replacement_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('minimum_stock_strategy');
        });
    }
};
