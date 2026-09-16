<?php

namespace App\Models;

use App\Enums\TaskLibraryPartAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLibraryPart extends Model
{
    protected $fillable = [
        'task_library_id',
        'part_id',
        'action',
        'needs_replacement',
        'quantity_required',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'action' => TaskLibraryPartAction::class,
            'needs_replacement' => 'boolean',
            'quantity_required' => 'integer',
        ];
    }

    public function taskLibrary(): BelongsTo
    {
        return $this->belongsTo(TaskLibrary::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
