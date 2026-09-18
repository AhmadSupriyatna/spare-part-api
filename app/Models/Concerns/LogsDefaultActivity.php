<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The "who did what" activity log (Spatie activitylog, installed since
 * Fase 0 but never actually wired to any model until now) — logs create/
 * update/delete on the model's fillable attributes, changed fields only,
 * causer auto-resolved from the authenticated request. Branch scoping for
 * the log viewer happens at query time via BranchScopeResolver against
 * each activity's subject, not by storing branch_id on the log row itself.
 */
trait LogsDefaultActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
