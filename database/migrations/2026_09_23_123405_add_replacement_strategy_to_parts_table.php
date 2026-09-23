<?php

use App\Enums\PartReplacementStrategy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('replacement_strategy')->default(PartReplacementStrategy::OnDemand->value)->after('is_active');
        });

        // Backward-compatible backfill for parts that already existed before
        // this column existed: a part that already has estimated_lifetime_hours
        // set is kept on LifeBased so the currently-running PM Lifetime
        // behaviour for it does not change. Everything else keeps the safe
        // schema default (OnDemand), which never activates automatic
        // lifetime scheduling.
        DB::table('parts')
            ->whereNotNull('estimated_lifetime_hours')
            ->update(['replacement_strategy' => PartReplacementStrategy::LifeBased->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('replacement_strategy');
        });
    }
};
