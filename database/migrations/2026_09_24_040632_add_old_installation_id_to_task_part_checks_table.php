<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Step 3 — persists the OLD installation a planned checklist
 * replacement closed out, alongside the existing part_installation_id
 * (which already points at the NEW installation, added by the
 * 2026_09_17_090001 migration and never renamed). Before this,
 * TaskService::completeChecklist() resolved the old installation into a
 * local variable and discarded it once remove()/install() ran — there was
 * no way to prove which installation a given replacement actually replaced.
 *
 * Same FK behavior as part_installation_id (nullable + nullOnDelete): a
 * completed historical checklist row must survive even if the referenced
 * PartInstallation is ever removed from the database, and "no old
 * installation" is a legitimate state (a fresh/first-time install, or any
 * row completed before this migration) — not an error condition.
 *
 * No backfill: historical rows completed before this migration have no
 * provable old installation (see Phase 3 Step 2's audit) and stay null
 * rather than being guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->foreignId('old_installation_id')->nullable()->after('part_installation_id')
                ->constrained('part_installations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('old_installation_id');
        });
    }
};
