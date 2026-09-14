<?php

namespace App\Models;

use App\Enums\ReplacementRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartReplacementRequest extends Model
{
    protected $fillable = [
        'part_id',
        'equipment_id',
        'quantity_used',
        'requested_by_name',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'part_installation_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_used' => 'integer',
            'status' => ReplacementRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function partInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class);
    }
}
