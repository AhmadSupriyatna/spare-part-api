<?php

namespace App\Models;

use App\Enums\ReplacementRequestEventType;
use App\Enums\ReplacementRequestStatus;
use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `part_installation_id` is kept for old single-unit requests approved
 * before `items` existed, and is still set (to the first new installation)
 * on every new approval for backward-compatible reads — but `items` is now
 * the actual source of truth for which installations were replaced,
 * especially once quantity_used > 1.
 *
 * `event_type` is decided once, at creation, from the part's
 * replacement_strategy at that moment (see
 * ReplacementRequestEventType::fromReplacementStrategy() and
 * PublicBreakdownController::store()) — never from client input, and never
 * recomputed later even if the part's strategy changes afterward.
 */
class PartReplacementRequest extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'part_id',
        'equipment_id',
        'quantity_used',
        'event_type',
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
            'event_type' => ReplacementRequestEventType::class,
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

    public function items(): HasMany
    {
        return $this->hasMany(PartReplacementRequestItem::class, 'replacement_request_id');
    }
}
