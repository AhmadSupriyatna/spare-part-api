<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        $now = now();
        foreach (['equipment' => 'equipment'] as $type => $table) {
            $names = DB::table($table)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->pluck('category');

            if ($names->isNotEmpty()) {
                DB::table('asset_categories')->insert(
                    $names->map(fn ($name) => [
                        'type' => $type,
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
