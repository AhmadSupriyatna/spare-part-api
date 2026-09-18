<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RackLevel extends Model
{
    protected $fillable = [
        'rack_id',
        'level_number',
    ];

    protected function casts(): array
    {
        return [
            'level_number' => 'integer',
        ];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * "A1" — the rack's label plus this level's number, level 1 being the
     * ground/bottom level. Half of a bin's full code (see
     * Location::generateCode()).
     */
    public function code(): string
    {
        return $this->rack->label.$this->level_number;
    }

    /**
     * Next level number for a rack — 1 is the ground/bottom level, counting
     * up from whatever's free (reuses a freed level number rather than
     * always growing, same reasoning as Rack::nextLabel()).
     */
    public static function nextLevelNumber(int $rackId): int
    {
        $used = static::where('rack_id', $rackId)->pluck('level_number')->all();

        for ($n = 1; $n <= count($used) + 1; $n++) {
            if (! in_array($n, $used, true)) {
                return $n;
            }
        }

        return count($used) + 1;
    }
}
