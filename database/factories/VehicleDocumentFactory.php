<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleDocument>
 */
class VehicleDocumentFactory extends Factory
{
    /**
     * Define the model's default state: a yearly insurance policy, valid for months.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'agency_id' => fn (array $attributes) => Vehicle::withoutGlobalScopes()->find($attributes['vehicle_id'])->agency_id,
            'type' => DocumentType::Insurance,
            'reference' => fake()->bothify('POL-#####'),
            'provider' => fake()->randomElement(['Wafa Assurance', 'AXA Assurance Maroc', 'RMA']),
            'issue_date' => now()->subMonths(2)->toDateString(),
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'cost' => 5500,
        ];
    }

    public function type(DocumentType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'expiry_date' => $type->hasExpiry() ? $attributes['expiry_date'] : null,
        ]);
    }

    /**
     * Expires in $days days (negative = already expired).
     */
    public function expiresIn(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'issue_date' => now()->addDays($days)->subYear()->addDay()->toDateString(),
            'expiry_date' => now()->addDays($days)->toDateString(),
        ]);
    }
}
