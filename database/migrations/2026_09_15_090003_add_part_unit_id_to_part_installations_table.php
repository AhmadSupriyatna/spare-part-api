<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PartInstallation is now one mounting cycle of a PartUnit — multiple
 * installations of the same part_id can be simultaneously active on one
 * equipment as long as they're different units (e.g. 4 bearings on one
 * gearbox, each its own unit). part_id stays on this table too (denormalized)
 * so every existing query reading $installation->part_id keeps working
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->foreignId('part_unit_id')->nullable()->after('part_id')
                ->constrained('part_units')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('part_installations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_unit_id');
        });
    }
};
