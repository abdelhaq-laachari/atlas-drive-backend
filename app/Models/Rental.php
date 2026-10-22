<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\ChargeDecision;
use App\Enums\ChargeKind;
use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\NumberedPerAgency;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\RentalFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nothing on a rental is mass-assignable: every field is set on purpose by the booking code.
 */
class Rental extends Model
{
    /** @use HasFactory<RentalFactory> */
    use BelongsToAgency, HasFactory, LogsActivity, NumberedPerAgency;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'reserved',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rental_number' => 'integer',
            'status' => RentalStatus::class,
            'pickup_at' => UtcDateTime::class,
            'return_at' => UtcDateTime::class,
            'days' => 'integer',
            'daily_price' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_method' => PaymentMethod::class,
            'deposit_number' => 'integer',
            'deposit_kept' => 'decimal:2',
            'deposit_returned_at' => UtcDateTime::class,
            'cancelled_at' => UtcDateTime::class,
            'handed_over_at' => UtcDateTime::class,
            'handover_mileage' => 'integer',
            'handover_fuel' => 'integer',
            'returned_at' => UtcDateTime::class,
            'return_mileage' => 'integer',
            'return_fuel' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Numbered deposit receipt DEP-0001 (per agency) when a deposit is taken.
        static::creating(function (Rental $rental) {
            if (Money::cents($rental->deposit_amount ?? 0) > 0) {
                $rental->deposit_number ??= static::withoutGlobalScopes()
                    ->where('agency_id', $rental->agency_id)
                    ->max('deposit_number') + 1;
            }
        });
    }

    public function numberColumn(): string
    {
        return 'rental_number';
    }

    public function numberPrefix(): string
    {
        return 'R-';
    }

    public function numberPad(): int
    {
        return 4;
    }

    /**
     * Load everything the rental page needs.
     */
    public function loadDetails(): static
    {
        return $this->load(['vehicle', 'customer', 'driver', 'charges', 'payments', 'damages']);
    }

    // ---- Relations ----

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Who signs and pays.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Who drives (often the same person as the customer).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'driver_id');
    }

    /**
     * @return HasMany<RentalCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(RentalCharge::class);
    }

    /**
     * @return HasMany<RentalPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(RentalPayment::class);
    }

    /**
     * Add a charge line. The decision (charge / reduce / waive) follows from suggested vs amount;
     * with no suggested price (return facts), 0 = waive ("no charge").
     */
    public function addCharge(ChargeKind $kind, string $label, int|float|string|null $suggested, int|float|string $amount, ?string $reason = null): RentalCharge
    {
        $decision = ChargeDecision::for($suggested, $amount);

        $charge = new RentalCharge;
        $charge->agency_id = $this->agency_id; // also works with nobody logged in (seeders, scheduled tasks)
        $charge->rental()->associate($this);
        $charge->kind = $kind;
        $charge->label = $label;
        $charge->suggested = $suggested === null ? null : Money::round($suggested);
        $charge->amount = Money::round($amount);
        $charge->decision = $decision;
        // Without a suggestion the note is the agent's free comment ("loyal client"); with one, it explains a discount.
        $charge->reason = ($suggested === null || $decision->needsReason()) ? $reason : null;
        $charge->save();

        return $charge;
    }

    /**
     * Frozen damage marks (hand-over and return), for the contract.
     *
     * @return HasMany<RentalDamage, $this>
     */
    public function damages(): HasMany
    {
        return $this->hasMany(RentalDamage::class)->orderBy('id');
    }

    // ---- Codes and money ----

    /**
     * @return Attribute<string, never>
     */
    protected function rentalCode(): Attribute
    {
        return Attribute::get(fn () => $this->code());
    }

    /**
     * Deposit receipt number shown to people, e.g. DEP-0003.
     *
     * @return Attribute<?string, never>
     */
    protected function depositCode(): Attribute
    {
        return Attribute::get(fn () => $this->deposit_number
            ? 'DEP-'.str_pad((string) $this->deposit_number, 4, '0', STR_PAD_LEFT)
            : null);
    }

    /**
     * Sum of the charge lines (what the customer owes in total).
     */
    public function total(): string
    {
        return Money::sum($this->charges->pluck('amount'));
    }

    public function paid(): string
    {
        return Money::sum($this->payments->pluck('amount'));
    }

    /**
     * Still to pay (negative = the agency owes money back).
     */
    public function balance(): string
    {
        return Money::sum([$this->total(), -1 * (float) $this->paid()]);
    }

    // ---- Late / overdue ----

    /**
     * Minutes past the planned return, once the agency's grace period is over (0 while not late).
     */
    public function minutesLate(?CarbonInterface $now = null, ?int $graceMinutes = null): int
    {
        if ($this->status !== RentalStatus::Active) {
            return 0;
        }

        $now ??= now();
        $graceMinutes ??= $this->agency->late_grace_minutes;
        $minutes = (int) floor(($now->getTimestamp() - $this->return_at->getTimestamp()) / 60);

        return $minutes > $graceMinutes ? $minutes : 0;
    }

    public function isReserved(): bool
    {
        return $this->status === RentalStatus::Reserved;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, RentalStatus::blocking(), true);
    }

    public function isOverdue(?CarbonInterface $now = null, ?int $graceMinutes = null): bool
    {
        return $this->minutesLate($now, $graceMinutes) > 0;
    }

    /**
     * Status to show: like the stored one, but "overdue" for a late active rental.
     */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status->value;
    }

    /**
     * Active rentals past their return time plus the grace period (the car hasn't come back).
     */
    #[Scope]
    protected function overdue(Builder $query, int $graceMinutes): void
    {
        $query->where('status', RentalStatus::Active)
            ->where('return_at', '<', now()->subMinutes($graceMinutes));
    }

    // ---- Footprints ----

    public function activityLabel(): string
    {
        return $this->code().' '.$this->vehicle?->fleet_code;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function activityAction(array $changes): string
    {
        if (array_key_exists('status', $changes)) {
            return match ($changes['status']['new']) {
                'active' => 'handed_over',
                'completed' => 'returned',
                'cancelled' => 'cancelled',
                default => 'updated',
            };
        }

        return array_key_exists('return_at', $changes) ? 'extended' : 'updated';
    }
}
