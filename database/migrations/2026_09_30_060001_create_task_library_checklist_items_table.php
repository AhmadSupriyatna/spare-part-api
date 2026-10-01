<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generic condition-check item on a Task Library recipe — e.g. "Periksa
 * kebocoran" — independent of the part-replacement checklist
 * (task_library_parts). Snapshotted onto task_checklist_items whenever a
 * Task is generated, same relationship task_library_parts has to
 * task_part_checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_library_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_library_id')->constrained('task_libraries')->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_library_checklist_items');
    }
};
