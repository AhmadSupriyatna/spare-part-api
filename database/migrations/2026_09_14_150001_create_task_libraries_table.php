<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "recipe" for a preventive-maintenance activity: what to do, on which
 * equipment, and which parts it typically needs. Not auto-recurring — an
 * entry only turns into an actual dated Task once explicitly scheduled onto
 * the PM calendar (see PmSchedulingService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_libraries');
    }
};
