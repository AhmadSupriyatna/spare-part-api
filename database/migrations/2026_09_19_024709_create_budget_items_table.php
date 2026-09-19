<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per part on a Budget's equipment — the user-entered
     * "estimasi life time (%)" assumption for that part (100% = expected
     * to need replacing once in the budget year, 50% = once every two
     * years, 200% = twice a year), plus the unit_cost it was priced at and
     * the resulting estimated_cost, both snapshotted at save time so a
     * later part price change doesn't rewrite history (same rationale as
     * stock_ledger.unit_cost).
     */
    public function up(): void
    {
        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->decimal('estimated_lifetime_percent', 6, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_items');
    }
};
