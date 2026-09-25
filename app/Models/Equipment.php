<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /**
     * Line.code-Machine.code-Equipment.code — each of those 3 is only
     * unique WITHIN its own parent (see the unique(['line_id'/'machine_id',
     * 'code']) constraints), so this composed string is what's actually
     * unique enough to safely build a global identifier from (e.g. a Task
     * Library code) — the bare equipment.code alone is not, and two
     * equipment under different machines/lines can legitimately share one.
     */
    public function hierarchicalCode(): string
    {
        return "{$this->machine->line->code}-{$this->machine->code}-{$this->code}";
    }

    /**
     * Deterministic, collision-safe within its Machine (matches the
     * unique(['machine_id', 'code']) constraint): an uppercase slug of the
     * name, with a numeric suffix appended only if that exact slug is
     * already taken by a sibling Equipment on the same Machine. Never
     * touches an already-persisted code — this is only ever called once,
     * at creation, from EquipmentController::store().
     */
    public static function generateCode(string $name, int $machineId): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $code = $base;

        for ($suffix = 2; static::where('machine_id', $machineId)->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}-{$suffix}";
        }

        return $code;
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
