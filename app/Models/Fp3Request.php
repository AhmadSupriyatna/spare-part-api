<?php

namespace App\Models;

use App\Enums\Fp3Disposition;
use App\Enums\Fp3Status;
use App\Models\Concerns\LogsDefaultActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Fp3Request extends Model
{
    use LogsDefaultActivity;

    protected $fillable = [
        'branch_id',
        'code',
        'requester_name',
        'department',
        'description',
        'request_photo_path',
        'status',
        'due_date',
        'received_at',
        'received_by',
        'work_description',
        'completed_at',
        'executor_names',
        'area_condition_after',
        'completion_photo_path',
        'disposition',
        'disposition_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => Fp3Status::class,
            'disposition' => Fp3Disposition::class,
            'due_date' => 'date',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function partUsages(): HasMany
    {
        return $this->hasMany(Fp3PartUsage::class);
    }

    public function requestPhotoUrl(): ?string
    {
        return $this->request_photo_path ? Storage::disk('public')->url($this->request_photo_path) : null;
    }

    public function completionPhotoUrl(): ?string
    {
        return $this->completion_photo_path ? Storage::disk('public')->url($this->completion_photo_path) : null;
    }

    /**
     * Same "past due, still open" rule as Task::isOverdue() — only
     * meaningful while the request hasn't reached a terminal state yet.
     */
    public function isOverdue(): bool
    {
        if ($this->status !== Fp3Status::Pending && $this->status !== Fp3Status::InProgress) {
            return false;
        }

        return $this->due_date !== null && $this->due_date->isPast();
    }

    /**
     * "{sequence}/FP3/{month}/{year}" per branch, mirroring the paper form's
     * own "No. : __ /Teknik/ __ / __ / __" numbering shape closely enough to
     * stay recognizable, without trying to reproduce it byte-for-byte.
     */
    public static function generateCode(int $branchId): string
    {
        $sequence = static::where('branch_id', $branchId)->count() + 1;

        return sprintf('%d/FP3/%s/%s', $sequence, now()->format('m'), now()->format('Y'));
    }
}
