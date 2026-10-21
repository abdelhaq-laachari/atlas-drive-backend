<?php

namespace Database\Factories;

use App\Enums\IdType;
use App\Models\Agency;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 *
 * Invented people only: ID numbers start with "XX".
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state: a Moroccan adult with a licence held for years.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+2126'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'city' => 'Casablanca',
            'id_type' => IdType::Cin,
            'id_number' => 'XX'.fake()->unique()->numerify('######'),
            'nationality' => 'Moroccan',
            'birth_date' => '1990-04-12',
            'licence_number' => 'XX-LIC-'.fake()->numerify('#####'),
            'licence_issue_date' => '2010-06-01',
        ];
    }

    public function blocked(string $reason = 'Unpaid damage'): static
    {
        return $this->state(fn (array $attributes) => [
            'blocked_at' => now(),
            'block_reason' => $reason,
        ]);
    }

    public function archived(string $reason = 'Duplicate'): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
            'archive_reason' => $reason,
        ]);
    }
}
