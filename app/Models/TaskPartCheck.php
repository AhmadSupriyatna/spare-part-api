<?php

namespace App\Models;

use App\Enums\FindingSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TaskPartCheck extends Model
{
    protected $fillable = [
        'task_id',
        'task_checklist_item_id',
        'part_id',
        'quantity_planned',
        'is_replaced',
        'quantity_used',
        'reason',
        'part_installation_id',
        'old_installation_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_planned' => 'integer',
            'is_replaced' => 'boolean',
            'quantity_used' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(TaskChecklistItem::class, 'task_checklist_item_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * The installation this replacement produced — see oldInstallation()
     * for the one it closed out, if any.
     */
    public function partInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class);
    }

    /**
     * The installation this replacement closed out, if any — persisted by
     * TaskService::completeChecklist() alongside partInstallation() (Phase 3
     * Step 3). Null for a non-replacement row, a fresh/first-time install
     * with nothing to replace, or any row completed before this column
     * existed — all three are legitimate, not an error.
     */
    public function oldInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class, 'old_installation_id');
    }

    /**
     * Reverse of Finding::taskPartCheck() — deliberately NOT backed by a
     * new task_part_checks.finding_id column. Finding.source_type/source_id
     * is the one and only place this link is stored; adding a second FK
     * here would create two pointers that could silently disagree. The
     * source_type constraint matters because source_id is just an integer
     * — without it, a Finding reported against a PartReplacementRequest
     * that happens to share the same numeric id as this TaskPartCheck would
     * incorrectly match.
     */
    public function finding(): HasOne
    {
        return $this->hasOne(Finding::class, 'source_id')
            ->where('source_type', FindingSourceType::TaskPartCheck);
    }
}
