<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global list (like `units`, not per-branch) of department names for the
 * FP3 "Departemen" field — a controlled vocabulary managed in Settings, the
 * same shape as Unit: consuming records (fp3_requests.department) still
 * store the plain name as a string, not a foreign key, mirroring how
 * Part.unit already works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
