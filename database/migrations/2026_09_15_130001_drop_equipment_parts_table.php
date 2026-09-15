<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM (equipment_parts) is removed: it was a manually-maintained "which
 * parts should this equipment have" spec that real usage left mostly
 * incomplete, and its only two consumers — the breakdown QR scan's
 * equipment picker and the legacy WorkOrder auto-task quantity default —
 * both now read the equipment/part relation from actual PartInstallation
 * records instead (the Line > Equipment > Part page's data), which is
 * always populated and reflects reality rather than a separate plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('equipment_parts');
    }

    public function down(): void
    {
        Schema::create('equipment_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->unsignedInteger('quantity_required')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['equipment_id', 'part_id']);
        });
    }
};
