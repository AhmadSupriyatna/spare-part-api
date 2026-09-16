<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per drag-to-reschedule action on the PM calendar — see the
 * migration for why this is a log rather than just overwriting due_date.
 */
class TaskReschedule extends Model
{
    protected $fillable = [
        'task_id',
        'previous_due_date',
        'new_due_date',
        'reason',
        'rescheduled_by',
    ];

    protected function casts(): array
    {
        return [
            'previous_due_date' => 'date',
            'new_due_date' => 'date',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }
}
