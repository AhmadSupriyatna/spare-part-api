<?php

namespace App\Models;

use App\Enums\StockAlertLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlert extends Model
{
    protected $fillable = [
        'part_stock_id',
        'level',
        'quantity_on_hand_at_trigger',
        'is_resolved',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => StockAlertLevel::class,
            'quantity_on_hand_at_trigger' => 'integer',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }
}
