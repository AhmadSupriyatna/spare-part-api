<?php

namespace App\Models;

use App\Exceptions\InvalidFp3PartUsageException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Spare Part Yang Dipakai | Kode | Jumlah | Keterangan" — exactly one of
 * part_id (a system-registered Part, still issues stock through
 * Fp3RequestService::complete()) or part_name_manual (free text for
 * something not registered at all, no stock movement) must be set, never
 * both, never neither — same XOR shape as Machine/Equipment's outside-line
 * variants.
 */
class Fp3PartUsage extends Model
{
    protected $fillable = [
        'fp3_request_id',
        'part_id',
        'part_name_manual',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Fp3PartUsage $usage) {
            $hasPart = $usage->part_id !== null;
            $hasManual = $usage->part_name_manual !== null && $usage->part_name_manual !== '';

            if ($hasPart === $hasManual) {
                throw new InvalidFp3PartUsageException(
                    $hasPart
                        ? 'Spare part tidak boleh punya part_id dan nama manual sekaligus.'
                        : 'Spare part harus dipilih dari sistem (part_id) atau diisi manual (part_name_manual).'
                );
            }
        });
    }

    public function fp3Request(): BelongsTo
    {
        return $this->belongsTo(Fp3Request::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function isRegistered(): bool
    {
        return $this->part_id !== null;
    }
}
