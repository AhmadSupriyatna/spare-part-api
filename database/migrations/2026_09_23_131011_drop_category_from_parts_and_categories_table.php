<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category is no longer Part's business classification — Replacement
     * Strategy took its place (see the parts.replacement_strategy
     * migration). The `categories` managed suggestion list and
     * `parts.category` only ever existed to serve that old classification,
     * so both are retired together. Nothing else references either.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::dropIfExists('categories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('category')->nullable()->after('unit');
        });
    }
};
