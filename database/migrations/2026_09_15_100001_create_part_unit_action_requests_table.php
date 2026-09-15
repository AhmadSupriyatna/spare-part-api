<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the QR-per-unit public flow: a technician scans a specific PartUnit's
 * QR (printed once it's removed and heading for repair) and reports "I just
 * pulled this off" (remove) or "this repaired unit just went back in"
 * (reinstall) — no login required, mirroring part_replacement_requests. The
 * actual PartUnit/PartInstallation state only changes once an
 * Engineer/Teknisi approves it (see PartUnitActionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_unit_action_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_unit_id')->constrained('part_units')->cascadeOnDelete();
            $table->string('action'); // remove | reinstall
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('requested_by_name');
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('resulting_installation_id')->nullable()
                ->constrained('part_installations')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_unit_action_requests');
    }
};
