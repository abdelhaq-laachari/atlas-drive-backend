<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\MaintenanceStatus;
use App\Enums\RentalStatus;
use App\Enums\Transmission;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\NumberedPerAgency;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'make',
    'model',
    'year',
    'plate',
    'vin',
    'category',
    'fuel',
    'transmission',
    'seats',
    'doors',
    'color',
    'mileage',
    'first_registration',
    'notes',
    'daily_price',
    'weekly_price',
    'monthly_price',
    'deposit',
    'included_km_per_day',
    'extra_km_fee',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use BelongsToAgency, HasFactory, LogsActivity, NumberedPerAgency;

    /**
     * Not shown in footprints (plate_key always follows plate).
     *
     * @var list<string>
     */
    protected array $activityIgnored = ['plate_key'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fleet_number' => 'integer',
            'year' => 'integer',
            'category' => VehicleCategory::class,
            'fuel' => FuelType::class,
            'transmission' => Transmission::class,
            'seats' => 'integer',
            'doors' => 'integer',
            'mileage' => 'integer',
            'first_registration' => 'date',
            'status' => VehicleStatus::class,
            'daily_price' => 'decimal:2',
            'weekly_price' => 'decimal:2',
            'monthly_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'included_km_per_day' => 'integer',
            'extra_km_fee' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    public function numberColumn(): string
    {
        return 'fleet_number';
    }

    public function numberPrefix(): string
    {
        return 'V-';
    }

    /**
     * Display form: uppercase, "-" between parts. "30577 b 6" and "30577 - B - 6" → "30577-B-6".
     */
    public static function normalizePlate(?string $plate): string
    {
        $plate = mb_strtoupper(trim((string) $plate));
        $plate = preg_replace('/\s*-\s*/u', '-', $plate);

        return preg_replace('/\s+/u', '-', $plate);
    }

    /**
     * Comparison key: letters (Latin or Arabic) and digits only. "30577-B-6" → "30577B6".
     */
    public static function plateKey(?string $plate): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper((string) $plate));
    }

    /**
     * Setting the plate also sets its comparison key.
     *
     * @return Attribute<string, string>
     */
    protected function plate(): Attribute
    {
        return Attribute::set(fn (?string $value) => [
            'plate' => static::normalizePlate($value),
            'plate_key' => static::plateKey($value),
        ]);
    }

    /**
     * Fleet code shown to people, e.g. V-007.
     *
     * @return Attribute<string, never>
     */
    protected function fleetCode(): Attribute
    {
        return Attribute::get(fn () => $this->code());
    }

    /**
     * Insurance, inspection, vignette, registration — current ones and history.
     *
     * @return HasMany<VehicleDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /**
     * Damage record: open damage (on the car now) and repaired damage (history).
     *
     * @return HasMany<VehicleDamage, $this>
     */
    public function damages(): HasMany
    {
        return $this->hasMany(VehicleDamage::class);
    }

    /**
     * @return HasMany<Rental, $this>
     */
    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    /**
     * After a rental changes: Rented if a rental has the car out, Reserved if one is waiting,
     * otherwise Available. A car in maintenance / out of service is left alone.
     */
    public function syncStatusWithRentals(): void
    {
        if (! in_array($this->status, [VehicleStatus::Available, VehicleStatus::Reserved, VehicleStatus::Rented], true)) {
            return;
        }

        $statuses = $this->rentals()->whereIn('status', RentalStatus::blocking())->pluck('status');

        $this->status = match (true) {
            $statuses->contains(RentalStatus::Active) => VehicleStatus::Rented,
            $statuses->contains(RentalStatus::Reserved) => VehicleStatus::Reserved,
            default => VehicleStatus::Available,
        };

        $this->save();
    }

    /**
     * @return HasMany<MaintenanceJob, $this>
     */
    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }

    /**
     * Back from the garage: Available (or Reserved / Rented from its rentals) unless another job
     * still has it at the garage.
     */
    public function releaseFromMaintenance(): void
    {
        $stillAtGarage = $this->maintenanceJobs()->where('status', MaintenanceStatus::InProgress)->exists();

        if ($this->status !== VehicleStatus::Maintenance || $stillAtGarage) {
            return;
        }

        $this->status = VehicleStatus::Available;
        $this->save();
        $this->syncStatusWithRentals();
    }

    public function activityLabel(): string
    {
        return $this->fleet_code.' '.$this->make.' '.$this->model;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        if (array_key_exists('archived_at', $changes)) {
            return $changes['archived_at']['new'] === null ? 'restored' : 'archived';
        }

        return array_keys($changes) === ['status'] ? 'status_changed' : 'updated';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Cars still in the fleet (not archived).
     */
    #[Scope]
    protected function inFleet(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }
}
