<?php

namespace App\Models;

use App\Enums\PartReplacementStrategy;
use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `category` stays in the schema (not fillable here) purely to preserve
 * old rows' original label — new code always reads/writes
 * replacement_strategy, the current classification dimension.
 */
class Budget extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'branch_id',
        'replacement_strategy',
        'year',
        'planned_amount',
        'corrective_amount',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'planned_amount' => 'decimal:2',
            'corrective_amount' => 'decimal:2',
            'replacement_strategy' => PartReplacementStrategy::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalAmount(): string
    {
        return bcadd($this->planned_amount, $this->corrective_amount, 2);
    }
}
