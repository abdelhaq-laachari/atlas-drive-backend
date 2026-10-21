<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $daily = fake()->randomElement([250, 270, 300, 350, 450]);

        return [
            'agency_id' => Agency::factory(),
            'make' => 'Dacia',
            'model' => fake()->randomElement(['Logan', 'Sandero', 'Duster']),
            'year' => fake()->numberBetween(2019, 2025),
            'plate' => fake()->unique()->numerify('#####').'-'.fake()->randomElement(['A', 'B', 'D']).'-'.fake()->numberBetween(1, 9),
            'vin' => null,
            'category' => VehicleCategory::Economy,
            'fuel' => FuelType::Diesel,
            'transmission' => Transmission::Manual,
            'seats' => 5,
            'doors' => 5,
            'color' => fake()->randomElement(['White', 'Grey', 'Black', 'Silver']),
            'mileage' => fake()->numberBetween(5000, 120000),
            'first_registration' => null,
            'status' => VehicleStatus::Available,
            'daily_price' => $daily,
            'weekly_price' => $daily * 6,
            'monthly_price' => $daily * 22,
            'deposit' => 3000,
            'included_km_per_day' => 250,
            'extra_km_fee' => 1.5,
        ];
    }

    public function status(VehicleStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function archived(string $reason = 'Sold'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VehicleStatus::OutOfService,
            'archived_at' => now(),
            'archive_reason' => $reason,
        ]);
    }
}
