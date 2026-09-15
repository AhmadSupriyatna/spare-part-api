<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only record of every runtime-hours reading logged for a line —
 * previously ProductionLine.runtime_hours was just a mutable counter with
 * no history of when/how much it changed. Each row is one reading: the
 * meter value before, the value the technician just reported, and the
 * difference between them for that period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_runtime_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('lines')->cascadeOnDelete();
            $table->unsignedInteger('previous_hours');
            $table->unsignedInteger('new_hours');
            $table->unsignedInteger('hours_added');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['line_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_runtime_logs');
    }
};
