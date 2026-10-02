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
        Schema::table('parts', function (Blueprint $table) {
            // Opt-in, per-part — "Part Passport" (individual unit tracking
            // starting from arrival, not just from first install) is only
            // worth the overhead for high-value/critical parts, not every
            // bolt and O-ring. See PartLifecycleService::receiveUnits().
            $table->boolean('has_passport')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('has_passport');
        });
    }
};
