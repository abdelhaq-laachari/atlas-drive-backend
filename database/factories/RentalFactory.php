<?php

namespace Database\Factories;

use App\Enums\RentalStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rental>
 *
 * Car and customer belong to the rental's agency. Use ->for($agency) or pass vehicle_id / customer_id.
 */
class RentalFactory extends Factory
{
    /**
     * Define the model's default state: a reservation in 2 days for 3 days, no deposit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'vehicle_id' => fn (array $attributes) => Vehicle::factory()->create(['agency_id' => $attributes['agency_id']])->id,
            'customer_id' => fn (array $attributes) => Customer::factory()->create(['agency_id' => $attributes['agency_id']])->id,
            'driver_id' => fn (array $attributes) => $attributes['customer_id'],
            'status' => RentalStatus::Reserved,
            'pickup_at' => now()->addDays(2)->setTime(10, 0),
            'return_at' => now()->addDays(5)->setTime(10, 0),
            'days' => 3,
            'daily_price' => 270,
            'deposit_amount' => 0,
        ];
    }

    /**
     * Car out with the customer from $fromDays to $toDays (days from now; negative = past).
     */
    public function active(int $fromDays = -2, int $toDays = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RentalStatus::Active,
            'pickup_at' => now()->addDays($fromDays),
            'return_at' => now()->addDays($toDays),
        ]);
    }

    public function between(string $pickupUtc, string $returnUtc): static
    {
        return $this->state(fn (array $attributes) => [
            'pickup_at' => $pickupUtc,
            'return_at' => $returnUtc,
        ]);
    }

    public function status(RentalStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
