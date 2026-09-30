<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "FP3" (Formulir Permintaan Perbaikan dan Pembuatan) — a digitized version
 * of a paper repair/fabrication request form. Deliberately has no
 * equipment_id/machine_id/line_id at all: a request can be for anything
 * (a facility fix, a fabrication job) with no registered machine behind it,
 * so branch_id is the only location scope, set from the QR's own printed
 * URL the same way every other QR-per-branch flow in this app works.
 *
 * Two independent "sides", matching the paper form's two sections:
 *   - Pengaju (requester) — public, no login: requester_name, department,
 *     description, request_photo_path, all set once at submission.
 *   - Pelaksana (executor) — set by whichever Engineer/Supervisor claims it
 *     via Workspace: received_at/received_by, then at completion
 *     work_description, completed_at, executor_names (free text — not tied
 *     to a User account, since the paper form's "Nama Pelaksana" can name
 *     anyone who helped, not just whoever is logged in), area_condition,
 *     completion_photo_path, and disposition (the paper's
 *     OPEN/CLOSED/CLOSED-with-note final status, separate from `status`
 *     which tracks the plain pending/in_progress/completed/cancelled work
 *     lifecycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fp3_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            // Pengaju
            $table->string('requester_name');
            $table->string('department');
            $table->text('description');
            $table->string('request_photo_path');

            // Work lifecycle
            $table->string('status')->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            // Pelaksana — filled at completion
            $table->text('work_description')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('executor_names')->nullable();
            $table->text('area_condition_after')->nullable();
            $table->string('completion_photo_path')->nullable();
            $table->string('disposition')->nullable();
            $table->text('disposition_note')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fp3_requests');
    }
};
