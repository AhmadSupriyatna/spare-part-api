<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budget moves from "one row per part category" to "one row per
     * equipment" — the user wants to plan/see cost per equipment (with a
     * per-part breakdown, see budget_items), not a company-wide category
     * bucket. No budgets exist yet in any real use of this app, so this is
     * a straight column swap rather than a data migration.
     */
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'category', 'year']);
            $table->dropColumn('category');
            $table->foreignId('equipment_id')->after('branch_id')->constrained('equipment')->cascadeOnDelete();
            $table->unique(['branch_id', 'equipment_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'equipment_id', 'year']);
            $table->dropConstrainedForeignId('equipment_id');
            $table->string('category')->default('');
            $table->unique(['branch_id', 'category', 'year']);
        });
    }
};
