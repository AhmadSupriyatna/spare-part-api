<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = the annual budget for one part category at one branch.
     * `category` is stored as an empty string (not null) for the
     * "Tanpa Kategori" bucket, so the unique constraint below actually
     * enforces one row per branch+category+year — Postgres treats NULLs
     * as distinct from each other in unique indexes, which would let
     * duplicate "uncategorized" rows slip through if this were nullable.
     */
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('');
            $table->unsignedSmallInteger('year');
            $table->decimal('planned_amount', 14, 2)->default(0);
            $table->decimal('corrective_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'category', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
