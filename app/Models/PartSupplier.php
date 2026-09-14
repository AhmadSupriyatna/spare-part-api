<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartSupplier extends Model
{
    protected $fillable = [
        'part_id',
        'supplier_id',
        'price',
        'lead_time_days',
        'is_preferred',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
