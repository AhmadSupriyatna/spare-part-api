<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Part extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_master_no',
        'name',
        'description',
        'unit',
        'category',
        'price',
        'estimated_lifetime_hours',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'estimated_lifetime_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(PartStock::class);
    }

    public function partSuppliers(): HasMany
    {
        return $this->hasMany(PartSupplier::class);
    }

    public function equipmentParts(): HasMany
    {
        return $this->hasMany(EquipmentPart::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(PartInstallation::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(PartUnit::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function replacementRequests(): HasMany
    {
        return $this->hasMany(PartReplacementRequest::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
