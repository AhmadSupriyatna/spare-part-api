<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLibraryPart extends Model
{
    protected $fillable = [
        'task_library_id',
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

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
