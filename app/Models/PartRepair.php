<?php

namespace App\Models;

use App\Enums\PartRepairDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartRepair extends Model
{
    protected $fillable = [
        'part_unit_id',
        'part_installation_id',
        'removed_at',
        'disposition',
        'repaired_at',
        'notes',
        'reinstalled_installation_id',
    ];

    protected function casts(): array
    {
        return [
            'disposition' => PartRepairDisposition::class,
            'removed_at' => 'datetime',
            'repaired_at' => 'datetime',
        ];
    }

    public function partUnit(): BelongsTo
    {
        return $this->belongsTo(PartUnit::class);
    }

    /**
     * The installation cycle that ended and led to this repair record.
     */
    public function partInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class);
    }

    /**
     * The new installation this unit went into once repaired, if any.
     */
    public function reinstalledInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class, 'reinstalled_installation_id');
    }
}
