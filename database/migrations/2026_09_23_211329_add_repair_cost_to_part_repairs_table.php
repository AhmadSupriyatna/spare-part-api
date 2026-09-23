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
        Schema::table('part_repairs', function (Blueprint $table) {
            $table->decimal('repair_cost', 14, 2)->nullable()->after('repaired_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('part_repairs', function (Blueprint $table) {
            $table->dropColumn('repair_cost');
        });
    }
};
