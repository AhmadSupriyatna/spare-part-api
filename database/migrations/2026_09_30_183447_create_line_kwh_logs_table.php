<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * One row per Line per calendar week — an append-only weekly usage
     * record, entered either as a cumulative meter reading (previous/new
     * populated, kwh_used = their difference) or as the week's usage
     * typed directly (previous/new left null, kwh_used = that value),
     * depending on CompanySetting::kwh_input_mode at the time it was
     * recorded — kept per-row rather than assumed global so a later mode
     * switch never reinterprets older entries. Unique per (line, week)
     * so re-submitting the same week corrects it (upsert) instead of
     * creating a duplicate — see LineController::addKwh().
     */
    public function up(): void
    {
        Schema::create('line_kwh_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('lines')->cascadeOnDelete();
            $table->date('week_start_date');
            $table->string('mode');
            $table->unsignedInteger('previous_reading')->nullable();
            $table->unsignedInteger('new_reading')->nullable();
            $table->unsignedInteger('kwh_used');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['line_id', 'week_start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('line_kwh_logs');
    }
};
