<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetItem extends Model
{
    protected $fillable = [
        'part_id',
        'estimated_lifetime_percent',
        'price_increase_percent',
        'unit_cost',
        'estimated_cost',
    ];

    protected function casts(): array
    {
        return [
            'estimated_lifetime_percent' => 'decimal:2',
            'price_increase_percent' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
