<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tier/shelf within a Rack — level 1 is the ground/bottom level. A
 * rack's label plus a level's number is the "A1" the user described
 * ("rak A tingkat 1/dasar"); combined with a Bin's number that becomes the
 * full "A1-B1" location code (see Location::generateCode()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rack_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rack_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('level_number');
            $table->timestamps();

            $table->unique(['rack_id', 'level_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_levels');
    }
};
