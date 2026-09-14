<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskPartCheck extends Model
{
    protected $fillable = [
        'task_id',
        'part_id',
        'quantity_planned',
        'is_replaced',
        'quantity_used',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_planned' => 'integer',
            'is_replaced' => 'boolean',
            'quantity_used' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
