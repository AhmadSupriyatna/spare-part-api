<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    protected $fillable = [
        'branch_id',
        'label',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(RackLevel::class);
    }

    /**
     * Lowest unused single-letter label (A-Z) for a branch — picked from
     * what's actually free rather than a running count, so deleting a rack
     * frees its letter for reuse instead of leaving a gap (same idea as
     * PartInstallation::resolveSlotLabel()). Falls back to a running
     * two-letter label in the unlikely event all 26 are taken.
     */
    public static function nextLabel(int $branchId): string
    {
        $used = static::where('branch_id', $branchId)->pluck('label')->all();

        for ($i = 0; $i < 26; $i++) {
            $candidate = chr(65 + $i);

            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return 'A'.(count($used) - 25);
    }
}
