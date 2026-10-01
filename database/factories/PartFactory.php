<?php

namespace Database\Factories;

use App\Enums\PartReplacementStrategy;
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
        // --- AC / Tata Udara ---
        ['name' => 'Freon R32', 'category' => 'AC', 'unit' => 'kg'],
        ['name' => 'Filter Udara AC Split', 'category' => 'AC', 'unit' => 'pcs'],
        ['name' => 'Kapasitor AC 25uF', 'category' => 'AC', 'unit' => 'pcs'],
        ['name' => 'Kondensor AC Split 1PK', 'category' => 'AC', 'unit' => 'set'],
        ['name' => 'Evaporator Coil AC', 'category' => 'AC', 'unit' => 'set'],
        ['name' => 'Thermostat Digital AC', 'category' => 'AC', 'unit' => 'pcs'],
        ['name' => 'Motor Fan Indoor AC', 'category' => 'AC', 'unit' => 'pcs'],
        // --- Material Handling (Forklift/Hand Lift) ---
        ['name' => 'Roda Hand Lift Poly', 'category' => 'Material Handling', 'unit' => 'pcs'],
        ['name' => 'Selang Hidrolik Forklift', 'category' => 'Material Handling', 'unit' => 'meter'],
        ['name' => 'Garpu Forklift', 'category' => 'Material Handling', 'unit' => 'pasang'],
        ['name' => 'Aki Forklift 24V', 'category' => 'Material Handling', 'unit' => 'pcs'],
        ['name' => 'Oli Hidrolik ISO 46', 'category' => 'Material Handling', 'unit' => 'liter'],
        // --- Peralatan Tangan (Bor/Gerinda/Heat Gun) ---
        ['name' => 'Mata Gerinda Potong 4 Inch', 'category' => 'Peralatan Tangan', 'unit' => 'pcs'],
        ['name' => 'Mata Gerinda Asah', 'category' => 'Peralatan Tangan', 'unit' => 'pcs'],
        ['name' => 'Carbon Brush Bor', 'category' => 'Peralatan Tangan', 'unit' => 'pasang'],
        ['name' => 'Chuck Bor 13mm', 'category' => 'Peralatan Tangan', 'unit' => 'pcs'],
        ['name' => 'Elemen Pemanas Heat Gun', 'category' => 'Peralatan Tangan', 'unit' => 'pcs'],
        ['name' => 'Kabel Power Universal', 'category' => 'Peralatan Tangan', 'unit' => 'pcs'],
        // --- Scrubbler / Cleaning ---
        ['name' => 'Brush Scrubbler Nylon', 'category' => 'Cleaning', 'unit' => 'pcs'],
        ['name' => 'Squeegee Blade Scrubbler', 'category' => 'Cleaning', 'unit' => 'pcs'],
        ['name' => 'Baterai Scrubbler 24V', 'category' => 'Cleaning', 'unit' => 'set'],
        // --- Ink Coder / Printing ---
        ['name' => 'Ink Cartridge Continuous Printer', 'category' => 'Ink Coder', 'unit' => 'pcs'],
        ['name' => 'Print Head Inkjet', 'category' => 'Ink Coder', 'unit' => 'pcs'],
        ['name' => 'Solvent Pembersih Nozzle', 'category' => 'Ink Coder', 'unit' => 'liter'],
        ['name' => 'Filter Ink Coder', 'category' => 'Ink Coder', 'unit' => 'pcs'],
        // --- Checkweigher / Sterilizer ---
        ['name' => 'Load Cell 5kg', 'category' => 'Checkweigher', 'unit' => 'pcs'],
        ['name' => 'Gasket Sterilizer Silikon', 'category' => 'Sterilizer', 'unit' => 'pcs'],
        ['name' => 'Sensor Suhu PT100', 'category' => 'Sterilizer', 'unit' => 'pcs'],
        ['name' => 'Kaca Indikator Level Sterilizer', 'category' => 'Sterilizer', 'unit' => 'pcs'],
        // --- Conveyor ---
        ['name' => 'Sprocket Conveyor', 'category' => 'Conveyor', 'unit' => 'pcs'],
        ['name' => 'Rantai Conveyor RS40', 'category' => 'Conveyor', 'unit' => 'meter'],
        ['name' => 'Roller Conveyor Idler', 'category' => 'Conveyor', 'unit' => 'pcs'],
        ['name' => 'Bantalan UCP 205', 'category' => 'Conveyor', 'unit' => 'pcs'],
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
            'item_master_no' => strtoupper('SP-'.$this->faker->unique()->bothify('####')),
            'name' => $item['name'],
            'description' => null,
            'unit' => $item['unit'],
            'price' => $this->faker->randomFloat(2, 10000, 750000),
            'is_active' => true,
            // FailureBased is the neutral pick for a factory default: no
            // lifetime-driven scheduling side effects for tests that don't
            // care which strategy a Part has. There is no DB-level default
            // to inherit any more (see PartReplacementStrategy), so this
            // must be set explicitly.
            'replacement_strategy' => PartReplacementStrategy::FailureBased->value,
        ];
    }
}
