<?php

namespace App\Models;

use App\Enums\ScheduleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskLibrary extends Model
{
    protected $fillable = [
        'equipment_id',
        'code',
        'title',
        'description',
        'schedule_type',
        'interval_days',
        'interval_hours',
        'estimated_duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule_type' => ScheduleType::class,
            'interval_days' => 'integer',
            'interval_hours' => 'integer',
            'estimated_duration_minutes' => 'integer',
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
