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
        Schema::table('line_kwh_logs', function (Blueprint $table) {
            // kVA is cumulative just like kWh — same previous/new reading
            // (or direct-usage) shape, same `mode` governs both. Nullable
            // because it's an optional addition: an entry can still be
            // logged with kWh alone, same as every row recorded before
            // this column existed.
            $table->unsignedInteger('previous_kva_reading')->nullable()->after('kwh_used');
            $table->unsignedInteger('new_kva_reading')->nullable()->after('previous_kva_reading');
            $table->unsignedInteger('kva_used')->nullable()->after('new_kva_reading');
            // Power Factor = kWh_used / kVA_used for the same week — derived
            // at write time, never typed in directly, so it's not an input
            // field anywhere; null whenever kva_used isn't available.
            $table->decimal('pf', 4, 3)->nullable()->after('kva_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('line_kwh_logs', function (Blueprint $table) {
            $table->dropColumn(['previous_kva_reading', 'new_kva_reading', 'kva_used', 'pf']);
        });
    }
};
