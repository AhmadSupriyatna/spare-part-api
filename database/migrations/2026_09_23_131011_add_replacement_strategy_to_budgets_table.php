<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budget's classification dimension moves from Part.category to
     * Part.replacement_strategy, matching the same shift on Part itself.
     * `category` is intentionally kept (not dropped) so any existing budget
     * row never loses its original label, even though new code no longer
     * reads or writes it — this is the "safe/compatible" path instead of a
     * destructive column swap, since real Budget history must not be lost.
     */
    public function up(): void
    {
        // Hardcoded literal, not PartReplacementStrategy::OnDemand->value —
        // that case no longer exists (Phase 3B Step 2), and this migration
        // must stay replayable via migrate:fresh forever, describing
        // exactly what it did when it ran, not today's enum.
        Schema::table('budgets', function (Blueprint $table) {
            $table->string('replacement_strategy')->default('on_demand')->after('category');
        });

        // Backfill existing budgets (if any) from the dominant
        // replacement_strategy among their own budget_items' parts — never
        // guessed, only derived from data the budget already links to. A
        // budget with no items, or a genuine tie between strategies, is left
        // on the safe OnDemand default rather than forced into a guess.
        $budgets = DB::table('budgets')->select('id')->get();

        foreach ($budgets as $budget) {
            $dominant = DB::table('budget_items')
                ->join('parts', 'parts.id', '=', 'budget_items.part_id')
                ->where('budget_items.budget_id', $budget->id)
                ->select('parts.replacement_strategy', DB::raw('count(*) as total'))
                ->groupBy('parts.replacement_strategy')
                ->orderByDesc('total')
                ->get();

            if ($dominant->count() === 1) {
                DB::table('budgets')->where('id', $budget->id)->update([
                    'replacement_strategy' => $dominant->first()->replacement_strategy,
                ]);
            }
        }

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'category', 'year']);
            $table->unique(['branch_id', 'replacement_strategy', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'replacement_strategy', 'year']);
            $table->unique(['branch_id', 'category', 'year']);
            $table->dropColumn('replacement_strategy');
        });
    }
};
