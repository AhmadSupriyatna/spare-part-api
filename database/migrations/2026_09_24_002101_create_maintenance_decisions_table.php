<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Maintenance Management, Step 2 (MaintenanceDecision foundation
 * only). This table only records a formal disposition against a Finding —
 * it does NOT execute anything. resulting_task_id/resulting_replacement_id
 * are pure traceability columns, always nullable at this step: nothing in
 * the codebase writes to them yet (that's Step 4).
 *
 * finding_id is UNIQUE — one Finding has at most one current Decision (MVP
 * rule, enforced at the database level as the strongest guarantee against
 * duplicates, not just a service-level check) — and cascades on delete
 * since a Decision cannot outlive the Finding it was made about.
 *
 * decided_by is a required, non-nullable FK with no ON DELETE override
 * (defaults to RESTRICT) — a MaintenanceDecision is an accountable action
 * and must always have a clear actor; a user who has made a Decision can't
 * be hard-deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
            $table->string('decision');
            $table->foreignId('decided_by')->constrained('users');
            $table->dateTime('decided_at');
            $table->text('notes')->nullable();
            $table->foreignId('resulting_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('resulting_replacement_request_id')->nullable()
                ->constrained('part_replacement_requests')->nullOnDelete();
            $table->timestamps();

            // .unique() chained onto a foreignId()->constrained() call is
            // silently dropped by Laravel's schema builder (it lands on the
            // ForeignKeyDefinition, not the column), so the "one Decision
            // per Finding" rule is declared as its own explicit index here.
            $table->unique('finding_id');
            $table->index('decided_by');
            $table->index('resulting_task_id');
            $table->index('resulting_replacement_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_decisions');
    }
};
