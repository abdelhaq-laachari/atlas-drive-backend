<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
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
    'ice',
    'if_number',
    'rc_number',
    'patente_number',
    'currency',
    'timezone',
    'late_grace_minutes',
    'fuel_price_per_quarter',
    'driver_min_age',
    'licence_min_years',
    'document_expiring_soon_days',
    'suggest_return_prices',
])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory, LogsActivity;

    /**
     * Shown in the "Complete your agency" checklist until filled in.
     */
    /**
     * Footprints: only the owner's changes in Settings (creation is done by the super admin).
     *
     * @var list<string>
     */
    protected array $activityEvents = ['updated'];

    public const PROFILE_FIELDS = ['phone', 'email', 'address', 'city', 'ice', 'if_number', 'rc_number', 'patente_number'];

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
        'suggest_return_prices' => false,
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
            'suggest_return_prices' => 'boolean',
        ];
    }

    /**
     * Profile fields still empty (for the "Complete your agency" checklist).
     *
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        return array_values(array_filter(self::PROFILE_FIELDS, fn (string $field) => blank($this->{$field})));
    }

    /**
     * Footprints: an agency is its own agency.
     */
    public function activityAgencyId(): int
    {
        return $this->id;
    }

    public function activityLabel(): string
    {
        return 'Agency settings';
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
