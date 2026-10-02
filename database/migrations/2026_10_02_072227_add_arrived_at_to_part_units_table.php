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
        Schema::table('part_units', function (Blueprint $table) {
            // Only populated for a has_passport Part's unit, created at
            // Stock In time rather than at first install (see
            // PartLifecycleService::receiveUnits()) — write-once, never
            // touched again by a later remove/repair/reinstall cycle.
            // Nothing in this codebase treats `created_at` as a meaningful
            // date, so this is a genuinely new fact, not a repurposed one.
            $table->timestamp('arrived_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('part_units', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
