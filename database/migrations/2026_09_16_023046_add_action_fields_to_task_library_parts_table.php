<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->string('action')->default('inspection')->after('part_id');
            $table->boolean('needs_replacement')->default(false)->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->dropColumn(['action', 'needs_replacement']);
        });
    }
};
