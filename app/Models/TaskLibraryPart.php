<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLibraryPart extends Model
{
    protected $fillable = [
        'task_library_id',
        'task_library_checklist_item_id',
        'part_id',
        'quantity_required',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'integer',
        ];
    }

    public function taskLibrary(): BelongsTo
    {
        return $this->belongsTo(TaskLibrary::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(TaskLibraryChecklistItem::class, 'task_library_checklist_item_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
