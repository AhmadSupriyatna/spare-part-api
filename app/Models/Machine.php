<?php

namespace App\Models;

use App\Exceptions\InvalidMachineHierarchyException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'line_id',
        'branch_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * "Mesin Luar Line": exactly one of line_id (production, reaches a
     * branch via line->branch_id) or branch_id (a standalone asset with no
     * production Line at all — a compressor room, a genset...) must be set,
     * never both, never neither. Mirrors Equipment::booted().
     */
    protected static function booted(): void
    {
        static::saving(function (Machine $machine) {
            $hasLine = $machine->line_id !== null;
            $hasBranch = $machine->branch_id !== null;

            if ($hasLine === $hasBranch) {
                throw new InvalidMachineHierarchyException(
                    $hasLine
                        ? 'Mesin tidak boleh punya line_id dan branch_id sekaligus.'
                        : 'Mesin harus punya line_id (mesin produksi) atau branch_id (mesin luar line).'
                );
            }
        });
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'line_id');
    }

    /** Only set for an outside-line machine — see resolvedBranchId(). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * "Mesin Luar Line" — the one place branch resolution for a Machine
     * should happen from now on, instead of every caller walking
     * line->branch_id itself (which crashes outright for an outside-line
     * machine, since it has no line at all). Equipment::resolvedBranchId()
     * delegates here too, so an Equipment under an outside-line Machine
     * resolves correctly without its own special case.
     */
    public function resolvedBranchId(): ?int
    {
        return $this->branch_id ?? $this->line?->branch_id;
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * Deterministic, collision-safe within its Line (matches the
     * unique(['line_id', 'code']) constraint): an uppercase slug of the
     * name, with a numeric suffix appended only if that exact slug is
     * already taken by a sibling Machine on the same Line. Never touches
     * an already-persisted code — this is only ever called once, at
     * creation, from MachineController::store().
     */
    public static function generateCode(string $name, int $lineId): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $code = $base;

        for ($suffix = 2; static::where('line_id', $lineId)->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}-{$suffix}";
        }

        return $code;
    }

    /**
     * Same generation rule as generateCode(), scoped by Branch instead of
     * Line (matches the new unique(['branch_id', 'code']) constraint) — for
     * an outside-line machine, which has no Line to scope by.
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
}
