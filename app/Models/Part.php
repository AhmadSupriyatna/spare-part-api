<?php

namespace App\Models;

use App\Enums\PartReplacementStrategy;
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
        'price',
        'estimated_lifetime_hours',
        'replacement_strategy',
        'image_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'estimated_lifetime_hours' => 'integer',
            'replacement_strategy' => PartReplacementStrategy::class,
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
