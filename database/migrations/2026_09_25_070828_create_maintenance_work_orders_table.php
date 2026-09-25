<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B Step 4 — the PM planning layer's execution package: one WO can
 * contain several Tasks (see tasks.maintenance_work_order_id). Deliberately
 * a NEW table, not a repurposing of the pre-existing `work_orders` — that
 * one predates Task Library, requires a single equipment_id + part_id +
 * schedule_type (a recurring-recipe shape incompatible with "many Tasks
 * across possibly-different equipment on one Line"), is still live-wired
 * to TaskService::complete()'s legacy recurrence path, and has zero
 * frontend usage today. Reusing it would either break that dormant path or
 * force awkward nullable fields onto an unrelated concept.
 *
 * line_id/planned_date are denormalized onto the WO itself (not solely
 * derived from its Tasks) so the planning list can filter/sort without
 * joining through every contained Task — set once at creation from
 * whatever the grouped Tasks agree on (see PmPlanningService), left null
 * when they don't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('line_id')->nullable()->constrained('lines')->nullOnDelete();
            $table->date('planned_date')->nullable();
            $table->string('status')->default('planning');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_orders');
    }
};
