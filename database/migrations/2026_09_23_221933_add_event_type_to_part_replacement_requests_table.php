<?php

use App\Enums\PartReplacementStrategy;
use App\Enums\ReplacementRequestEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * event_type marks what a QR scan report actually was — Failure (the
     * part's own FailureBased strategy expects this) or Breakdown (any
     * other strategy, i.e. replaced before its own plan). New requests get
     * it computed at creation time from the Part's replacement_strategy
     * (see ReplacementRequestEventType::fromReplacementStrategy() and
     * PublicBreakdownController::store()). Existing rows are backfilled the
     * same way, from each request's part's CURRENT strategy — the closest
     * available signal, since the request itself never recorded this.
     */
    public function up(): void
    {
        Schema::table('part_replacement_requests', function (Blueprint $table) {
            $table->string('event_type')->default(ReplacementRequestEventType::Breakdown->value)->after('quantity_used');
        });

        $failureBasedPartIds = DB::table('parts')
            ->where('replacement_strategy', PartReplacementStrategy::FailureBased->value)
            ->pluck('id');

        DB::table('part_replacement_requests')
            ->whereIn('part_id', $failureBasedPartIds)
            ->update(['event_type' => ReplacementRequestEventType::Failure->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('part_replacement_requests', function (Blueprint $table) {
            $table->dropColumn('event_type');
        });
    }
};
