<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklistItem extends Model
{
    protected $fillable = [
        'task_id',
        'task_library_checklist_item_id',
        'description',
        'condition_ok',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'condition_ok' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function libraryChecklistItem(): BelongsTo
    {
        return $this->belongsTo(TaskLibraryChecklistItem::class, 'task_library_checklist_item_id');
    }
}
