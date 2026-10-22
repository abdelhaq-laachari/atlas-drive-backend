<?php

namespace Database\Factories;

use App\Enums\MaintenanceType;
use App\Models\MaintenancePlan;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenancePlan>
 */
class MaintenancePlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'agency_id' => fn (array $attributes) => Vehicle::withoutGlobalScopes()->find($attributes['vehicle_id'])->agency_id,
            'type' => MaintenanceType::OilChange,
            'every_km' => 10000,
            'every_months' => 12,
            'start_date' => now()->subMonths(6)->toDateString(),
            'start_mileage' => fn (array $attributes) => max(0, Vehicle::withoutGlobalScopes()->find($attributes['vehicle_id'])->mileage - 4000),
        ];
    }
}
