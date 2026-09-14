<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentPart extends Model
{
    protected $table = 'equipment_parts';

    protected $fillable = [
        'equipment_id',
        'part_id',
        'quantity_required',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'integer',
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
}
