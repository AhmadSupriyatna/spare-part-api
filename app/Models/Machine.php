<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'line_id',
        'code',
        'name',
        'category',
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

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'line_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
