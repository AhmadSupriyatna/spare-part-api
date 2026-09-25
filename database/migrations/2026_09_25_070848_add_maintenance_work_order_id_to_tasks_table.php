<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate from the pre-existing (legacy, unused) `work_order_id` column —
 * see 2026_09_25_070828_create_maintenance_work_orders_table's docblock.
 * Nullable: a Task starts life with no WO ("needs planning") and is only
 * ever assigned one explicitly (or by the Step 5 overdue-fallback sweep).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('maintenance_work_order_id')
                ->nullable()
                ->after('work_order_id')
                ->constrained('maintenance_work_orders')
                ->nullOnDelete();

            $table->index(['maintenance_work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_work_order_id');
        });
    }
};
