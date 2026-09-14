<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    /**
     * 'pcs' must stay first — it's the Part migration's column default, so
     * every part created before this seeder ran (or without picking a unit)
     * relies on it existing.
     */
    private const UNITS = [
        'pcs', 'unit', 'set', 'pasang', 'lembar', 'roll', 'meter', 'liter', 'kg', 'box',
    ];

    public function run(): void
    {
        foreach (self::UNITS as $name) {
            Unit::firstOrCreate(['name' => $name]);
        }
    }
}
