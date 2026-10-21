<?php

namespace Database\Seeders;

use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * A few sample cars (same as the Vue prototype's sampleVehicles.js) for local development.
 */
class VehicleSeeder extends Seeder
{
    public function run(Agency $agency): void
    {
        $deposit = ['economy' => 3000, 'compact' => 4000, 'suv' => 6000, 'van' => 6000, 'luxury' => 15000];
        $kmFee = ['economy' => 1.5, 'compact' => 2, 'suv' => 2.5, 'van' => 2.5, 'luxury' => 4];

        $cars = [
            ['Dacia', 'Sandero', 2023, '48213-A-6', 'economy', 'diesel', 'manual', 5, 5, 'White', 38400, 250, 'available'],
            ['Dacia', 'Logan', 2022, '30577-B-6', 'economy', 'diesel', 'manual', 5, 4, 'Grey', 61200, 270, 'available'],
            ['Dacia', 'Logan', 2023, '51940-A-6', 'economy', 'diesel', 'manual', 5, 4, 'White', 42900, 270, 'reserved'],
            ['Kia', 'Picanto', 2023, '27318-D-1', 'economy', 'petrol', 'manual', 4, 5, 'Red', 29800, 250, 'available'],
            ['Hyundai', 'i10', 2022, '66402-B-6', 'economy', 'petrol', 'manual', 4, 5, 'Silver', 55300, 250, 'available'],
            ['Peugeot', '208', 2023, '19864-A-6', 'compact', 'diesel', 'manual', 5, 5, 'Blue', 33100, 350, 'rented'],
            ['Renault', 'Clio', 2024, '72015-D-6', 'compact', 'petrol', 'automatic', 5, 5, 'Black', 12800, 380, 'available'],
            ['Dacia', 'Duster', 2023, '40398-B-6', 'suv', 'diesel', 'manual', 5, 5, 'Orange', 47600, 450, 'available'],
            ['Hyundai', 'Tucson', 2024, '58217-A-6', 'suv', 'hybrid', 'automatic', 5, 5, 'White', 18900, 700, 'maintenance'],
            ['Mercedes', 'Classe C', 2023, '61490-D-6', 'luxury', 'diesel', 'automatic', 5, 4, 'Black', 26500, 1200, 'available'],
        ];

        foreach ($cars as [$make, $model, $year, $plate, $category, $fuel, $transmission, $seats, $doors, $color, $mileage, $daily, $status]) {
            $vehicle = new Vehicle([
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'plate' => $plate,
                'category' => $category,
                'fuel' => $fuel,
                'transmission' => $transmission,
                'seats' => $seats,
                'doors' => $doors,
                'color' => $color,
                'mileage' => $mileage,
                'first_registration' => $year.'-03-15',
                'daily_price' => $daily,
                'weekly_price' => $daily * 6,
                'monthly_price' => $daily * 22,
                'deposit' => $deposit[$category],
                'included_km_per_day' => 250,
                'extra_km_fee' => $kmFee[$category],
            ]);

            // Seeders run with nobody logged in, so the agency is set by hand.
            $vehicle->agency_id = $agency->id;
            $vehicle->status = VehicleStatus::from($status);
            $vehicle->save();
        }
    }
}
