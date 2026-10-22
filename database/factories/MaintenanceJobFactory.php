<?php

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\MaintenanceJob;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceJob>
 */
class MaintenanceJobFactory extends Factory
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
            'status' => MaintenanceStatus::Scheduled,
            'scheduled_for' => now()->addDays(2)->toDateString(),
            'garage' => 'Garage Atlas',
        ];
    }
}
