<?php

namespace App\Models;

use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'branch_id',
        'category',
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
