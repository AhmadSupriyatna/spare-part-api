<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_library_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_library_id')->constrained('task_libraries')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->unsignedInteger('quantity_required')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['task_library_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_library_parts');
    }
};
