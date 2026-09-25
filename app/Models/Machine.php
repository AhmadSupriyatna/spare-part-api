<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'line_id',
        'code',
        'name',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
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

    /**
     * Deterministic, collision-safe within its Line (matches the
     * unique(['line_id', 'code']) constraint): an uppercase slug of the
     * name, with a numeric suffix appended only if that exact slug is
     * already taken by a sibling Machine on the same Line. Never touches
     * an already-persisted code — this is only ever called once, at
     * creation, from MachineController::store().
     */
    public static function generateCode(string $name, int $lineId): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $code = $base;

        for ($suffix = 2; static::where('line_id', $lineId)->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}-{$suffix}";
        }

        return $code;
    }
}
