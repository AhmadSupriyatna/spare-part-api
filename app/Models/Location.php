<?php

namespace App\Models;

use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Bin — the leaf of the Rak > Tingkat > Bin hierarchy. `code` is
 * always server-generated ("{rack.label}{level_number}-B{bin_number}",
 * e.g. "A1-B1") at creation time, never accepted from a request.
 */
class Location extends Model
{
    use HasFactory, LogsDefaultActivity;

    protected $fillable = [
        'branch_id',
        'rack_level_id',
        'bin_number',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bin_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rackLevel(): BelongsTo
    {
        return $this->belongsTo(RackLevel::class);
    }

    public function partStocks(): HasMany
    {
        return $this->hasMany(PartStock::class);
    }

    /**
     * Next bin number for a rack level — 1-based, reuses a freed number
     * rather than always growing (see Rack::nextLabel()'s reasoning).
     */
    public static function nextBinNumber(int $rackLevelId): int
    {
        $used = static::where('rack_level_id', $rackLevelId)->pluck('bin_number')->all();

        for ($n = 1; $n <= count($used) + 1; $n++) {
            if (! in_array($n, $used, true)) {
                return $n;
            }
        }

        return count($used) + 1;
    }

    public static function generateCode(RackLevel $rackLevel, int $binNumber): string
    {
        return "{$rackLevel->code()}-B{$binNumber}";
    }
}
