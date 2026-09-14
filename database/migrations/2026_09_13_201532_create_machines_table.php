<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_id')->constrained('lines')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['line_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
