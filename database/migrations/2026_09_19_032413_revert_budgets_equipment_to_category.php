<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reverts the previous "per equipment" migration — per-equipment turned
     * out to be too granular to plan against ("kebanyakan kalau per
     * equipment"). Budget goes back to being scoped per part category, with
     * the per-equipment/per-line math now living inside the calculation
     * itself (BudgetForecastService) rather than the row's identity. Still
     * empty (nothing has used the equipment-scoped version), so a straight
     * column swap again.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'equipment_id', 'year']);
            $table->dropConstrainedForeignId('equipment_id');
            $table->string('category')->default('')->after('branch_id');
            $table->unique(['branch_id', 'category', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'category', 'year']);
            $table->dropColumn('category');
            $table->foreignId('equipment_id')->after('branch_id')->constrained('equipment')->cascadeOnDelete();
            $table->unique(['branch_id', 'equipment_id', 'year']);
        });
    }
};
