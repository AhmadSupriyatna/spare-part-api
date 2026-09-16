<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a checklist row to the actual PartInstallation it produced once
 * marked "diganti" and scanned — before this, completing a checklist only
 * decremented stock and never touched part_installations/part_units at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->foreignId('part_installation_id')->nullable()->after('reason')
                ->constrained('part_installations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_installation_id');
        });
    }
};
