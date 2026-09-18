<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Backfill from whatever category strings existing parts already
        // use, so the new managed list doesn't start empty and orphan
        // every part's current category out of the dropdown.
        $now = now();
        $existing = DB::table('parts')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        DB::table('categories')->insert(
            $existing->map(fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
