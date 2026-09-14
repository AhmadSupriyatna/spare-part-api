<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trackable physical instance of a part — created the first time it's
 * installed, and reused (not recreated) across every later remove/repair/
 * reinstall cycle, even onto a different equipment. This is what lets
 * "how many times has this exact unit been installed, where, for how long"
 * be answered, and what makes remaining lifetime accumulate across repairs
 * instead of resetting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->string('unit_code')->nullable();
            $table->string('status')->default('in_service'); // in_service | pending_repair | in_repair | available | scrapped
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_units');
    }
};
