<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLine extends Model
{
    use HasFactory;

    protected $table = 'lines';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'runtime_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'runtime_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class, 'line_id');
    }
}
