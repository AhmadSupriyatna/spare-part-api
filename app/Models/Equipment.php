<?php

namespace App\Models;

use App\Exceptions\InvalidEquipmentHierarchyException;
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
        'branch_id',
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

    /**
     * Phase 3B "Asset Outside Line": exactly one of machine_id (production,
     * reaches a branch via machine->line) or branch_id (an asset with no
     * production Line/Machine at all — an AC panel room, a hand pallet...)
     * must be set, never both, never neither. Enforced here — on every
     * save, not just at creation — rather than a DB CHECK constraint, since
     * this project has no precedent for those and this keeps behavior
     * identical across the Postgres (dev) / SQLite (test) drivers it runs
     * on.
     */
    protected static function booted(): void
    {
        static::saving(function (Equipment $equipment) {
            $hasMachine = $equipment->machine_id !== null;
            $hasBranch = $equipment->branch_id !== null;

            if ($hasMachine === $hasBranch) {
                throw new InvalidEquipmentHierarchyException(
                    $hasMachine
                        ? 'Equipment tidak boleh punya machine_id dan branch_id sekaligus.'
                        : 'Equipment harus punya machine_id (equipment produksi) atau branch_id (asset luar line).'
                );
            }
        });
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** Only set for an outside-line asset — see resolvedBranchId(). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Phase 3B "Asset Outside Line" — the one place branch resolution for
     * an Equipment should happen from now on, instead of every caller
     * walking machine->line->branch_id itself (which crashes outright for
     * an outside-line asset, since it has no machine at all). Delegates to
     * Machine::resolvedBranchId() rather than assuming machine->line, since
     * the Machine itself may now be an outside-line one ("Mesin Luar Line").
     */
    public function resolvedBranchId(): ?int
    {
        return $this->branch_id ?? $this->machine?->resolvedBranchId();
    }

    /**
     * Line.code-Machine.code-Equipment.code for production equipment — each
     * of those 3 is only unique WITHIN its own parent (see the
     * unique(['line_id'/'machine_id', 'code']) constraints), so this
     * composed string is what's actually unique enough to safely build a
     * global identifier from (e.g. a Task Library code) — the bare
     * equipment.code alone is not, and two equipment under different
     * machines/lines can legitimately share one.
     *
     * OL-{code} for an outside-line asset (Phase 3B) — there's no
     * line/machine to compose from, and this prefix is deliberately a
     * different shape (not a 3rd "-"-joined segment standing in for a fake
     * line/machine) so it's never mistaken for one.
     *
     * {machine.code}-{code} (2 segments, no OL- prefix) for equipment under
     * an outside-line Machine ("Mesin Luar Line") — it does have a real
     * Machine, just no Line above it to compose a 3rd segment from.
     */
    public function hierarchicalCode(): string
    {
        if ($this->machine_id === null) {
            return "OL-{$this->code}";
        }

        if ($this->machine->line_id === null) {
            return "{$this->machine->code}-{$this->code}";
        }

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

    /**
     * Same generation rule as generateCode(), scoped by Branch instead of
     * Machine (matches the new unique(['branch_id', 'code']) constraint) —
     * for an outside-line asset, which has no Machine to scope by.
     */
    public static function generateCodeForBranch(string $name, int $branchId): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $code = $base;

        for ($suffix = 2; static::where('branch_id', $branchId)->where('code', $code)->exists(); $suffix++) {
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
