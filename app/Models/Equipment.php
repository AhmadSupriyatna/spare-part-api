<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'machine_id',
        'code',
        'name',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function taskLibraries(): HasMany
    {
        return $this->hasMany(TaskLibrary::class);
    }

    public function equipmentParts(): HasMany
    {
        return $this->hasMany(EquipmentPart::class);
    }

    public function partInstallations(): HasMany
    {
        return $this->hasMany(PartInstallation::class);
    }

    public function replacementRequests(): HasMany
    {
        return $this->hasMany(PartReplacementRequest::class);
    }

    public function activeInstallation(?int $partId = null): ?PartInstallation
    {
        return $this->partInstallations()
            ->when($partId, fn ($q) => $q->where('part_id', $partId))
            ->whereNull('removed_at')
            ->latest('installed_at')
            ->first();
    }
}
