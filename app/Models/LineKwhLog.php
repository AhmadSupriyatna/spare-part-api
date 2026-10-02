<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineKwhLog extends Model
{
    protected $fillable = [
        'line_id',
        'week_start_date',
        'mode',
        'previous_reading',
        'new_reading',
        'kwh_used',
        'previous_kva_reading',
        'new_kva_reading',
        'kva_used',
        'pf',
        'recorded_by',
        'notes',
    ];

    /**
     * week_start_date is deliberately NOT cast to 'date' — Eloquent's date
     * cast serializes it to a full datetime string on write but leaves an
     * uncast plain string in updateOrCreate()'s lookup WHERE, so the two
     * never match and every resubmit for the same week hits the unique
     * constraint instead of updating it. Plain 'Y-m-d' strings compare and
     * sort correctly either way.
     */
    protected function casts(): array
    {
        return [
            'previous_reading' => 'integer',
            'new_reading' => 'integer',
            'kwh_used' => 'integer',
            'previous_kva_reading' => 'integer',
            'new_kva_reading' => 'integer',
            'kva_used' => 'integer',
            'pf' => 'float',
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
