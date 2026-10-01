<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per condition-check item a PM task's library entry planned for,
 * created up front (description snapshotted) when the task is scheduled —
 * mirrors task_part_checks' relationship to task_library_parts, but for
 * generic Kondisi Baik/Tidak checks instead of part replacement.
 * task_library_checklist_item_id is nullOnDelete so an edited/removed
 * library item never erases the historical record of what was actually
 * checked on a completed task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('task_library_checklist_item_id')->nullable()->constrained('task_library_checklist_items')->nullOnDelete();
            $table->string('description');
            $table->boolean('condition_ok')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
