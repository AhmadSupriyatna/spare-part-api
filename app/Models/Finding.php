<?php

namespace App\Models;

use App\Enums\FindingResult;
use App\Enums\FindingSeverity;
use App\Enums\FindingSourceType;
use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What was actually observed during a checklist/inspection or reported via
 * QR — independent of any decision taken about it. Immutable in spirit: a
 * correction is a new Finding, not an edit of this one, consistent with the
 * rest of the lifecycle audit trail in this app.
 *
 * source_type/source_id is a deliberately closed pair (only TaskPartCheck
 * or PartReplacementRequest) — not a real database FK since it spans two
 * unrelated tables. Ownership/consistency (does source_id really exist,
 * does it really belong to the claimed equipment/part) is enforced at the
 * service layer in a later step, not here.
 */
class Finding extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'source_type',
        'source_id',
        'equipment_id',
        'part_id',
        'description',
        'result',
        'severity',
        'recommendation',
        'reported_by',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => FindingSourceType::class,
            'result' => FindingResult::class,
            'severity' => FindingSeverity::class,
            'reported_at' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * At most one, enforced by a unique constraint on maintenance_decisions.
     * finding_id (see the Step 2 migration) — MVP rule, no versioning/
     * history chain of decisions per Finding yet.
     */
    public function decision(): HasOne
    {
        return $this->hasOne(MaintenanceDecision::class);
    }

    /**
     * Only meaningful when source_type is TaskPartCheck — a plain belongsTo
     * on source_id, not filtered by source_type, so callers must check
     * source_type first (see resolveSource()). Kept as two narrow, explicit
     * relations instead of a generic morphTo() so "only these two source
     * types exist" stays structural rather than only documented — a real
     * morphTo() would need a global Relation::morphMap() that also reaches
     * every other polymorphic relation in the app (e.g. StockLedger's own
     * reference_type/reference_id), which is out of scope for this step.
     */
    public function taskPartCheck(): BelongsTo
    {
        return $this->belongsTo(TaskPartCheck::class, 'source_id');
    }

    public function partReplacementRequest(): BelongsTo
    {
        return $this->belongsTo(PartReplacementRequest::class, 'source_id');
    }

    /**
     * Convenience read helper for whichever source this Finding actually
     * points to, resolved via source_type. Not a formal Eloquent relation —
     * can't be eager-loaded with with().
     */
    public function resolveSource(): TaskPartCheck|PartReplacementRequest|null
    {
        return match ($this->source_type) {
            FindingSourceType::TaskPartCheck => $this->taskPartCheck,
            FindingSourceType::PartReplacementRequest => $this->partReplacementRequest,
        };
    }
}
