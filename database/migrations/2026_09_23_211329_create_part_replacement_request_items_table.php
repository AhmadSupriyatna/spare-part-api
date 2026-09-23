<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = one physical unit involved in a breakdown replacement
     * approval — the relational structure a multi-unit request
     * (quantity_used > 1) needs, since `part_replacement_requests.
     * part_installation_id` can only ever point at one installation.
     * That legacy column is kept (see the model) for old single-unit
     * requests approved before this table existed; new approvals of any
     * quantity always get one row per unit here, which is now the source
     * of truth for "which installations were actually replaced."
     */
    public function up(): void
    {
        Schema::create('part_replacement_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replacement_request_id')->constrained('part_replacement_requests')->cascadeOnDelete();
            $table->foreignId('old_installation_id')->nullable()->constrained('part_installations')->nullOnDelete();
            $table->foreignId('new_installation_id')->nullable()->constrained('part_installations')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_replacement_request_items');
    }
};
