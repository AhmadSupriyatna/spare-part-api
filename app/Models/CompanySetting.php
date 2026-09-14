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
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['name' => 'Nama Perusahaan Anda']);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
