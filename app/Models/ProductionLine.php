<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLine extends Model
{
    use HasFactory;

    protected $table = 'lines';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'runtime_hours',
        'avg_weekly_operating_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'runtime_hours' => 'integer',
            'avg_weekly_operating_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * This line's own average weekly operating hours if set, otherwise the
     * company-wide default — used by PartLifetimeService's Life Based
     * due-date projection and BudgetForecastService's pcs/year estimate,
     * both of which used to read CompanySetting directly regardless of
     * which line a part actually runs on.
     */
    public function effectiveAvgWeeklyOperatingHours(): int
    {
        return $this->avg_weekly_operating_hours ?? (CompanySetting::current()->avg_weekly_operating_hours ?: 85);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class, 'line_id');
    }

    public function runtimeLogs(): HasMany
    {
        return $this->hasMany(LineRuntimeLog::class, 'line_id')->latest();
    }

    public function kwhLogs(): HasMany
    {
        return $this->hasMany(LineKwhLog::class, 'line_id')->latest('week_start_date');
    }
}
