<?php

namespace App\Models;

use App\Enums\PartUnitActionType;
use App\Enums\ReplacementRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartUnitActionRequest extends Model
{
    protected $fillable = [
        'part_unit_id',
        'action',
        'equipment_id',
        'branch_id',
        'requested_by_name',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'resulting_installation_id',
    ];

    protected function casts(): array
    {
        return [
            'action' => PartUnitActionType::class,
            'status' => ReplacementRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function partUnit(): BelongsTo
    {
        return $this->belongsTo(PartUnit::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resultingInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class, 'resulting_installation_id');
    }
}
