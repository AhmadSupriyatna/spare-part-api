<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spare part used on an FP3 report can now be either a registered Part
 * (part_id, unchanged — still issues stock) or a free-text name for
 * something not in the system at all (part_name_manual) — exactly one of
 * the two, enforced in Fp3PartUsage::booted(), the same XOR shape already
 * used for Machine/Equipment's outside-line variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fp3_part_usages', function (Blueprint $table) {
            $table->foreignId('part_id')->nullable()->change();
            $table->string('part_name_manual')->nullable()->after('part_id');
        });
    }

    public function down(): void
    {
        Schema::table('fp3_part_usages', function (Blueprint $table) {
            $table->dropColumn('part_name_manual');
            $table->foreignId('part_id')->nullable(false)->change();
        });
    }
};
