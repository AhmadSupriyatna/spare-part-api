<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskLibraryChecklistItem extends Model
{
    protected $fillable = [
        'task_library_id',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function taskLibrary(): BelongsTo
    {
        return $this->belongsTo(TaskLibrary::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(TaskLibraryPart::class, 'task_library_checklist_item_id');
    }
}
