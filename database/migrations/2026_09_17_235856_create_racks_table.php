<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A physical rack/shelving unit in a branch's warehouse — the top of the
 * new Rak > Tingkat > Bin hierarchy that replaced the old flat, free-text
 * rack/bin strings on `locations`. Labeled A, B, C... automatically
 * (Rack::nextLabel()), never typed by the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('label', 10);
            $table->timestamps();

            $table->unique(['branch_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('racks');
    }
};
