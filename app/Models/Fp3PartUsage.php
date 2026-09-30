<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Spare Part Yang Dipakai | Kode | Jumlah | Keterangan" — part_id is always a system-registered Part, quantity/notes filled by the Engineer at completion. */
class Fp3PartUsage extends Model
{
    protected $fillable = [
        'fp3_request_id',
        'part_id',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function fp3Request(): BelongsTo
    {
        return $this->belongsTo(Fp3Request::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
