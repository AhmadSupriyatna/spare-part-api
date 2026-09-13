<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'description',
        'unit',
        'category',
        'supplier_id',
        'location_id',
        'minimum_stock',
        'reorder_point',
        'reorder_quantity',
        'unit_cost',
        'quantity_on_hand',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'quantity_on_hand' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function stockLedgerEntries(): HasMany
    {
        return $this->hasMany(StockLedger::class);
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
