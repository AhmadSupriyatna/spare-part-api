<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineRuntimeLog extends Model
{
    protected $fillable = [
        'line_id',
        'previous_hours',
        'new_hours',
        'hours_added',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'previous_hours' => 'integer',
            'new_hours' => 'integer',
            'hours_added' => 'integer',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'line_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
