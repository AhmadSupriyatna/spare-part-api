<?php

namespace App\Models;

use App\Enums\ReorderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReorderRequest extends Model
{
    protected $fillable = [
        'part_stock_id',
        'supplier_id',
        'quantity_requested',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReorderStatus::class,
            'quantity_requested' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function partStock(): BelongsTo
    {
        return $this->belongsTo(PartStock::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, [ReorderStatus::Completed, ReorderStatus::Cancelled], true);
    }
}
