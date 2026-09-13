<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartStock extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_id',
        'branch_id',
        'supplier_id',
        'location_id',
        'minimum_stock',
        'reorder_point',
        'reorder_quantity',
        'unit_cost',
        'quantity_on_hand',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'quantity_on_hand' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(StockAlert::class);
    }

    public function reorderRequests(): HasMany
    {
        return $this->hasMany(ReorderRequest::class);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->quantity_on_hand <= $this->reorder_point;
    }

    public function isCritical(): bool
    {
        return $this->quantity_on_hand <= $this->minimum_stock;
    }
}
