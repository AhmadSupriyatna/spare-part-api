<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The baseline "how many running hours should this part type last" — set
 * once per part in the catalog, not per equipment. This replaces computing
 * a part's remaining life from whatever WorkOrder happens to reference that
 * equipment+part pair, which silently failed to cover parts with no such
 * WorkOrder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->unsignedInteger('estimated_lifetime_hours')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('estimated_lifetime_hours');
        });
    }
};
