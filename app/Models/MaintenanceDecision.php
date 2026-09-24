<?php

namespace App\Models;

use App\Enums\MaintenanceDecisionType;
use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A formal disposition made against a Finding — who decided, what they
 * decided, and (once Step 4 exists) a pointer to whatever follow-up record
 * actually got created because of it. This model never executes anything
 * itself: resulting_task_id/resulting_replacement_request_id are pure
 * traceability, filled in later by whichever service performs the actual
 * follow-up action, never guessed or auto-populated here.
 */
class MaintenanceDecision extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'finding_id',
        'decision',
        'decided_by',
        'decided_at',
        'notes',
        'resulting_task_id',
        'resulting_replacement_request_id',
    ];

    protected function casts(): array
    {
        return [
            'decision' => MaintenanceDecisionType::class,
            'decided_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function resultingTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'resulting_task_id');
    }

    public function resultingReplacementRequest(): BelongsTo
    {
        return $this->belongsTo(PartReplacementRequest::class, 'resulting_replacement_request_id');
    }
}
