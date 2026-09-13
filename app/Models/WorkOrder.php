<?php

namespace App\Models;

use App\Enums\ScheduleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_id',
        'part_id',
        'title',
        'description',
        'schedule_type',
        'interval_days',
        'interval_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schedule_type' => ScheduleType::class,
            'interval_days' => 'integer',
            'interval_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
