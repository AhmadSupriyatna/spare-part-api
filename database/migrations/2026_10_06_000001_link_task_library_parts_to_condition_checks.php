<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->foreignId('task_library_checklist_item_id')
                ->nullable()
                ->after('task_library_id')
                ->constrained('task_library_checklist_items')
                ->nullOnDelete();
        });

        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->foreignId('task_checklist_item_id')
                ->nullable()
                ->after('task_id')
                ->constrained('task_checklist_items')
                ->nullOnDelete();
        });

        $librariesWithUnassignedParts = DB::table('task_libraries')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('task_library_parts')
                    ->whereColumn('task_library_parts.task_library_id', 'task_libraries.id')
                    ->whereNull('task_library_parts.task_library_checklist_item_id');
            })
            ->get(['id']);

        foreach ($librariesWithUnassignedParts as $library) {
            $conditionItemId = DB::table('task_library_checklist_items')
                ->where('task_library_id', $library->id)
                ->where('description', 'Periksa kondisi part')
                ->value('id');

            if ($conditionItemId === null) {
                $nextOrder = (int) DB::table('task_library_checklist_items')
                    ->where('task_library_id', $library->id)
                    ->max('order') + 1;

                $conditionItemId = DB::table('task_library_checklist_items')->insertGetId([
                    'task_library_id' => $library->id,
                    'description' => 'Periksa kondisi part',
                    'order' => $nextOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('task_library_parts')
                ->where('task_library_id', $library->id)
                ->whereNull('task_library_checklist_item_id')
                ->update(['task_library_checklist_item_id' => $conditionItemId]);

            $tasks = DB::table('tasks')
                ->where('task_library_id', $library->id)
                ->get(['id', 'status']);
            $partIds = DB::table('task_library_parts')
                ->where('task_library_id', $library->id)
                ->where('task_library_checklist_item_id', $conditionItemId)
                ->pluck('part_id');

            foreach ($tasks as $task) {
                $taskConditionItemId = DB::table('task_checklist_items')
                    ->where('task_id', $task->id)
                    ->where('task_library_checklist_item_id', $conditionItemId)
                    ->value('id');

                if ($taskConditionItemId === null) {
                    $completedPartWasReplaced = DB::table('task_part_checks')
                        ->where('task_id', $task->id)
                        ->whereIn('part_id', $partIds)
                        ->where('is_replaced', true)
                        ->exists();

                    $taskConditionItemId = DB::table('task_checklist_items')->insertGetId([
                        'task_id' => $task->id,
                        'task_library_checklist_item_id' => $conditionItemId,
                        'description' => 'Periksa kondisi part',
                        'condition_ok' => $task->status === 'completed' ? ! $completedPartWasReplaced : null,
                        'notes' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('task_part_checks')
                    ->where('task_id', $task->id)
                    ->whereIn('part_id', $partIds)
                    ->update(['task_checklist_item_id' => $taskConditionItemId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('task_part_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_checklist_item_id');
        });

        Schema::table('task_library_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_library_checklist_item_id');
        });
    }
};
