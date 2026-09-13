<?php

namespace Database\Factories;

use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    /**
     * @var array<int, array{name: string, category: string, unit: string}>
     */
    private static array $catalog = [
        ['name' => 'Bearing 6205 ZZ', 'category' => 'Mekanikal', 'unit' => 'pcs'],
        ['name' => 'V-Belt A-42', 'category' => 'Mekanikal', 'unit' => 'pcs'],
        ['name' => 'Seal Oli 30x47x7', 'category' => 'Mekanikal', 'unit' => 'pcs'],
        ['name' => 'Gear Reducer 1:20', 'category' => 'Mekanikal', 'unit' => 'set'],
        ['name' => 'Kopling Fleksibel L-100', 'category' => 'Mekanikal', 'unit' => 'pcs'],
        ['name' => 'Kontaktor Magnetik 32A', 'category' => 'Elektrikal', 'unit' => 'pcs'],
        ['name' => 'MCB 3 Phase 20A', 'category' => 'Elektrikal', 'unit' => 'pcs'],
        ['name' => 'Kabel NYY 3x2.5mm', 'category' => 'Elektrikal', 'unit' => 'meter'],
        ['name' => 'Motor Servo AC 1.5kW', 'category' => 'Elektrikal', 'unit' => 'set'],
        ['name' => 'Sensor Proximity Induktif', 'category' => 'Elektrikal', 'unit' => 'pcs'],
        ['name' => 'Selang Hidrolik 1/2 inch', 'category' => 'Hidrolik', 'unit' => 'meter'],
        ['name' => 'Pompa Hidrolik Gear Pump', 'category' => 'Hidrolik', 'unit' => 'set'],
        ['name' => 'Cylinder Pneumatik 32x100', 'category' => 'Pneumatik', 'unit' => 'pcs'],
        ['name' => 'Solenoid Valve 5/2 Way', 'category' => 'Pneumatik', 'unit' => 'pcs'],
        ['name' => 'Filter Regulator Udara', 'category' => 'Pneumatik', 'unit' => 'pcs'],
        ['name' => 'Oli Gear SAE 90', 'category' => 'Consumable', 'unit' => 'liter'],
        ['name' => 'Grease Lithium EP2', 'category' => 'Consumable', 'unit' => 'box'],
        ['name' => 'Sarung Tangan Safety', 'category' => 'Consumable', 'unit' => 'box'],
        ['name' => 'Majun Pembersih', 'category' => 'Consumable', 'unit' => 'box'],
        ['name' => 'Mata Bor HSS 10mm', 'category' => 'Consumable', 'unit' => 'pcs'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $item = $this->faker->unique()->randomElement(self::$catalog);

        return [
            'sku' => strtoupper('SP-'.$this->faker->unique()->bothify('####')),
            'name' => $item['name'],
            'description' => null,
            'unit' => $item['unit'],
            'category' => $item['category'],
            'is_active' => true,
        ];
    }
}
