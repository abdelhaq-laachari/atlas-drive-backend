<?php

namespace Database\Seeders;

use App\Enums\DamageStage;
use App\Enums\DamageStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use Illuminate\Database\Seeder;

/**
 * Sample damage (like the Vue prototype's sampleVehicleDamages.js):
 *   V-007 two open damages + a repaired windscreen crack (history)
 *   V-006 open chip on the windscreen
 *   V-001 open scratch found at the return of its last rental
 */
class VehicleDamageSeeder extends Seeder
{
    public function run(Agency $agency): void
    {
        $car = fn (int $number) => Vehicle::withoutGlobalScopes()
            ->where('agency_id', $agency->id)->where('fleet_number', $number)->firstOrFail();

        $this->add($agency, $car(7), ['x' => 0.3, 'y' => 0.93, 'area' => 'Rear bumper', 'type' => 'scratch', 'size' => 'small', 'note' => 'Rear bumper, about 5 cm'], 28);
        $this->add($agency, $car(7), ['x' => 0.77, 'y' => 0.45, 'area' => 'Front right door', 'type' => 'dent', 'size' => 'medium', 'note' => 'Front right door'], 16);
        $this->add($agency, $car(6), ['x' => 0.42, 'y' => 0.36, 'area' => 'Windscreen', 'type' => 'crack', 'size' => 'small', 'note' => 'Chip on the windscreen'], 9);
        $this->add($agency, $car(1), ['x' => 0.26, 'y' => 0.23, 'area' => 'Front left wing', 'type' => 'scratch', 'size' => 'small', 'note' => 'Parking scrape'], 16, DamageStage::Return);

        // Already repaired: history, not on the drawing
        $this->add($agency, $car(7), ['x' => 0.55, 'y' => 0.38, 'area' => 'Windscreen', 'type' => 'crack', 'size' => 'medium', 'note' => 'Stone impact'], 40, null, [
            'status' => DamageStatus::Repaired,
            'repaired_at' => now()->subDays(30)->toDateString(),
            'repair_cost' => 600,
            'repair_note' => 'Sample glass repair shop',
        ]);
    }

    /**
     * @param  array<string, mixed>  $mark
     * @param  array<string, mixed>  $repair
     */
    private function add(Agency $agency, Vehicle $vehicle, array $mark, int $daysAgo, ?DamageStage $stage = null, array $repair = []): void
    {
        $damage = new VehicleDamage([...$mark, 'found_at' => now()->subDays($daysAgo)->setTime(10, 0)]);

        // Seeders run with nobody logged in, so the agency is set by hand.
        $damage->agency_id = $agency->id;
        $damage->found_stage = $stage;
        $damage->forceFill($repair);
        $damage->vehicle()->associate($vehicle);
        $damage->save();
    }
}
