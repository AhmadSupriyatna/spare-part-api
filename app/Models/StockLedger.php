<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLedger extends Model
{
    public const TYPE_RECEIVING = 'receiving';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_RETURN = 'return';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_CORRECTION = 'correction';

    protected $table = 'stock_ledger';

    /**
     * Ledger entries are append-only: never update or delete a row after it is written.
     */
    protected $fillable = [
        'part_id',
        'type',
        'quantity_change',
        'balance_after',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
