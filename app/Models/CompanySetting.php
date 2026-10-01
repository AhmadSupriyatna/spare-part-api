<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Singleton: there is only ever one row (id=1) since one company owns every
 * branch. Use CompanySetting::current() instead of querying directly.
 */
class CompanySetting extends Model
{
    protected $fillable = [
        'name',
        'logo_path',
        'app_name',
        'app_logo_path',
        'avg_weekly_operating_hours',
        'kwh_input_mode',
    ];

    protected function casts(): array
    {
        return [
            'avg_weekly_operating_hours' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['name' => 'Nama Perusahaan Anda']);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function appLogoUrl(): ?string
    {
        return $this->app_logo_path ? Storage::disk('public')->url($this->app_logo_path) : null;
    }
}
