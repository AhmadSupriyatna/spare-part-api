<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Maintenance Management, Step 1 (Finding foundation only).
 *
 * A Finding is what was actually observed during a PM checklist/inspection
 * (source = task_part_check) or reported via QR (source =
 * part_replacement_request), independent of any decision made about it.
 * MaintenanceDecision (Step 2) and any integration with TaskService/
 * PartReplacementService are deliberately NOT part of this table.
 *
 * source_type/source_id is intentionally NOT a real database foreign key —
 * it spans two unrelated tables, so a plain FK constraint can't express it.
 * Integrity (does source_id really exist, and does it really belong to the
 * claimed equipment/part) is enforced at the service layer in a later step,
 * the same validate-before-mutate pattern already used throughout Phase 1
 * hardening (see ChecklistPartMismatchException, InvalidOldInstallationException,
 * PartStockBranchMismatchException).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->text('description');
            $table->string('result');
            $table->string('severity')->nullable();
            $table->text('recommendation')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reported_at');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('equipment_id');
            $table->index('part_id');
            $table->index('result');
            $table->index('severity');
            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
