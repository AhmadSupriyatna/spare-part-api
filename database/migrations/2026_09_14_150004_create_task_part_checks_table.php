<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per part a PM task's library entry planned for, created up front
 * when the task is scheduled (quantity_planned copied from task_library_parts).
 * Filled in at completion time: is_replaced + quantity_used, or is_replaced
 * false + a mandatory reason. Kept separate from tasks.part_stock_id/
 * quantity_used, which stay reserved for the single-part flow (work-order
 * generated and ad-hoc tasks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_part_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->unsignedInteger('quantity_planned')->default(1);
            $table->boolean('is_replaced')->nullable();
            $table->unsignedInteger('quantity_used')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_part_checks');
    }
};
