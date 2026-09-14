<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskLibrary extends Model
{
    protected $fillable = [
        'equipment_id',
        'title',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(TaskLibraryPart::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
