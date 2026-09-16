<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per drag-to-reschedule action on a PM task's calendar date — the
 * "Riwayat Reschedule" list below the PM calendar reads straight from this,
 * rather than only keeping the task's current due_date and losing the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->date('previous_due_date')->nullable();
            $table->date('new_due_date');
            // Required only when the shift from previous_due_date exceeds the
            // 7-day tolerance — enforced in RescheduleTaskRequest, not here.
            $table->text('reason')->nullable();
            $table->foreignId('rescheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reschedules');
    }
};
