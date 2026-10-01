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
        'global_lifetime_percent',
        'global_price_increase_percent',
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
            'global_lifetime_percent' => 'decimal:2',
            'global_price_increase_percent' => 'decimal:2',
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

    public function manualEntries(): HasMany
    {
        return $this->hasMany(BudgetManualEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * planned_amount (auto-forecast) + corrective_amount (manual top-up) +
     * every manual 'planned' entry — corrective_amount predates
     * BudgetManualEntry and is kept as-is for backward compatibility rather
     * than migrated into it.
     */
    public function totalAmount(): string
    {
        $manualPlanned = $this->manualEntries->where('type', BudgetManualEntry::TYPE_PLANNED)->reduce(
            fn (string $carry, BudgetManualEntry $entry) => bcadd($carry, (string) $entry->amount, 2),
            '0.00',
        );

        return bcadd(bcadd($this->planned_amount, $this->corrective_amount, 2), $manualPlanned, 2);
    }
}
