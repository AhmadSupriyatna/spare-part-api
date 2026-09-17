<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A persistent "address" (A, B, C...) for a part's position on one
 * equipment — unlike PartUnit.unit_code (global per part, across the whole
 * system), this is scoped per equipment+part and survives a replacement:
 * when a new installation closes out an old one, it inherits the old
 * one's slot_label rather than getting a fresh letter, so "Unit A" on this
 * equipment means the same physical position over time regardless of which
 * part actually occupies it. See PartLifecycleService::install().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->string('slot_label', 4)->nullable()->after('part_unit_id');
        });

        $this->backfillExistingActiveInstallations();
    }

    /**
     * Every installation created before this column existed has no
     * slot_label — assign one now to every currently-active installation,
     * grouped by equipment+part and lettered in installed_at order, so
     * existing data isn't left with blank addresses until it happens to
     * get replaced.
     */
    private function backfillExistingActiveInstallations(): void
    {
        $groups = DB::table('part_installations')
            ->select('equipment_id', 'part_id')
            ->whereNull('removed_at')
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $installations = DB::table('part_installations')
                ->where('equipment_id', $group->equipment_id)
                ->where('part_id', $group->part_id)
                ->whereNull('removed_at')
                ->orderBy('installed_at')
                ->get(['id']);

            foreach ($installations as $index => $installation) {
                DB::table('part_installations')
                    ->where('id', $installation->id)
                    ->update(['slot_label' => chr(65 + $index)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->dropColumn('slot_label');
        });
    }
};
