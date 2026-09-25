<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of planned maintenance activity a recipe represents
 * (LIFE_TIME/SCHEDULED_MAINTENANCE/INSPECTION — see
 * TaskLibraryMaintenanceCategory), separate from schedule_type (how/when a
 * Task gets created from it).
 *
 * Nullable, no default: the 2 Task Library rows that already existed at the
 * time of this migration (Phase 3B Step 3 audit) can't be safely classified
 * from their data alone —
 *   - "Inspeksi & Pelumasan Bulanan" (no schedule, 0 parts) reads as
 *     INSPECTION by its title, but "Pelumasan" (lubrication) is also a
 *     consumable-replacing activity, not pure inspection.
 *   - "pengecekan oring" (calendar/30 days, 1 part attached) reads as an
 *     inspection by its title too, but has both a schedule and a part
 *     checklist, which is equally consistent with SCHEDULED_MAINTENANCE.
 * Guessing either would be inventing a classification the data doesn't
 * actually support, so both are left NULL — to be set explicitly by
 * whoever owns them, via the "Ubah" form. Every NEW Task Library from this
 * point on must choose one (see StoreTaskLibraryRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_libraries', function (Blueprint $table) {
            $table->string('maintenance_category')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('task_libraries', function (Blueprint $table) {
            $table->dropColumn('maintenance_category');
        });
    }
};
