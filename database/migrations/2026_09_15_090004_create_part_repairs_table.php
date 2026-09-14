<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to a PartUnit after it's pulled off an equipment: sent for
 * repair (and later either back in service or scrapped) or scrapped
 * outright. Linked to the specific PartInstallation that just ended (for
 * traceability) and, once repaired and remounted, to the new
 * PartInstallation it went into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_unit_id')->constrained('part_units')->cascadeOnDelete();
            $table->foreignId('part_installation_id')->nullable()
                ->constrained('part_installations')->nullOnDelete();
            $table->dateTime('removed_at');
            $table->string('disposition')->default('pending'); // pending | in_repair | repaired | scrapped
            $table->dateTime('repaired_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reinstalled_installation_id')->nullable()
                ->constrained('part_installations')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_repairs');
    }
};
