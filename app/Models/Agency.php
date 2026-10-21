<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'phone',
    'email',
    'address',
    'city',
    'currency',
    'timezone',
    'late_grace_minutes',
    'fuel_price_per_quarter',
    'driver_min_age',
    'licence_min_years',
    'document_expiring_soon_days',
])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    /**
     * Defaults visible before the agency is saved (same values as the migration).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'MAD',
        'timezone' => 'Africa/Casablanca',
        'late_grace_minutes' => 60,
        'fuel_price_per_quarter' => '150.00',
        'driver_min_age' => 21,
        'licence_min_years' => 2,
        'document_expiring_soon_days' => 30,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'late_grace_minutes' => 'integer',
            'fuel_price_per_quarter' => 'decimal:2',
            'driver_min_age' => 'integer',
            'licence_min_years' => 'integer',
            'document_expiring_soon_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
