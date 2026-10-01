<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-form Budget line outside the automatic per-part forecast — see
 * the create_budget_manual_entries_table migration's docblock for the
 * 'planned' vs 'actual' distinction.
 */
class BudgetManualEntry extends Model
{
    public const TYPE_PLANNED = 'planned';

    public const TYPE_ACTUAL = 'actual';

    protected $fillable = [
        'budget_id',
        'type',
        'line_id',
        'machine_id',
        'description',
        'amount',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'date',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'line_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
