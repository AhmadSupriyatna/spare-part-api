<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-form line on a Budget that doesn't come from the automatic
 * per-part forecast: either extra planned spend the forecast can't
 * anticipate ('planned'), or an actual expense that never passed through
 * stock_ledger so BudgetRealizationService would otherwise miss it
 * entirely ('actual') — e.g. a service call paid directly, not via a
 * stock issue. Optionally tagged to a Line or a Machine (production or
 * outside-line — Machine already covers both), or left untagged for a
 * general/company-wide item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_manual_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('line_id')->nullable()->constrained('lines')->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('occurred_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['budget_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_manual_entries');
    }
};
