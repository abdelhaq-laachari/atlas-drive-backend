<?php

namespace Database\Factories;

use App\Enums\DamageSize;
use App\Enums\DamageStatus;
use App\Enums\DamageType;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleDamage>
 */
class VehicleDamageFactory extends Factory
{
    /**
     * Define the model's default state: a small open scratch on the rear bumper.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'agency_id' => fn (array $attributes) => Vehicle::withoutGlobalScopes()->find($attributes['vehicle_id'])->agency_id,
            'x' => 0.3,
            'y' => 0.93,
            'area' => 'Rear bumper',
            'type' => DamageType::Scratch,
            'size' => DamageSize::Small,
            'note' => 'About 5 cm',
            'found_at' => now()->subDays(10),
            'status' => DamageStatus::Open,
        ];
    }

    public function repaired(int $cost = 600): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DamageStatus::Repaired,
            'repaired_at' => now()->subDays(2)->toDateString(),
            'repair_cost' => $cost,
            'repair_note' => 'Body shop',
        ]);
    }
}
