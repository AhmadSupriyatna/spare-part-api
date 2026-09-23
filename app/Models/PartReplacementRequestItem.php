<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical unit swapped by a breakdown replacement approval — a request
 * with quantity_used = 3 gets three of these. See
 * PartReplacementService::approve().
 */
class PartReplacementRequestItem extends Model
{
    protected $fillable = [
        'replacement_request_id',
        'old_installation_id',
        'new_installation_id',
    ];

    public function replacementRequest(): BelongsTo
    {
        return $this->belongsTo(PartReplacementRequest::class);
    }

    public function oldInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class, 'old_installation_id');
    }

    public function newInstallation(): BelongsTo
    {
        return $this->belongsTo(PartInstallation::class, 'new_installation_id');
    }
}
