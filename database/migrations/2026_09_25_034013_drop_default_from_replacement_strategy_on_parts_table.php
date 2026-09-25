<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PartReplacementStrategy no longer has a safe catch-all case to fall back
 * to (OnDemand was removed — Phase 3B Step 2), so a new Part must never
 * silently inherit one at the database layer either. StorePartRequest
 * already requires the field explicitly; this drops the column default so
 * that guarantee doesn't rely on application code alone. The 3 existing
 * Parts already hold 'life_based' and are untouched by this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('replacement_strategy')->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('replacement_strategy')->default('on_demand')->change();
        });
    }
};
