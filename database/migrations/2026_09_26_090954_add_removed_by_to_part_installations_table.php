<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors the existing installed_by — nothing previously recorded who
 * actually pulled a part off (PartLifecycleService::remove()), which the
 * Repair Part history list needs to answer "oleh siapa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->foreignId('removed_by')->nullable()->after('removed_at_runtime_hours')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
        });
    }
};
