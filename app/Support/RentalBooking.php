<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything checked before a car is booked for a period (used by "quote" and by "create"):
 * errors block the booking, warnings are shown to staff but allow it.
 */
class RentalBooking
{
    public function __construct(private Agency $agency) {}

    /**
     * @return array{errors: array<string, string>, warnings: list<string>, days: int, daily_price: string, suggested_price: string, deposit: string}
     */
    public function check(
        Vehicle $vehicle,
        Customer $customer,
        Customer $driver,
        CarbonImmutable $pickup,
        CarbonImmutable $return,
        ?int $exceptRentalId = null,
    ): array {
        $errors = [];
        $warnings = [];
        $today = CarbonImmutable::now($this->agency->timezone)->startOfDay();

        // ---- Dates ----
        if ($pickup->lt($today)) {
            $errors['pickup_at'] = 'The pickup cannot be in the past.';
        }
        if ($return->lte($pickup)) {
            $errors['return_at'] = 'The return must be after the pickup.';
        }

        // ---- Car ----
        if ($vehicle->isArchived()) {
            $errors['vehicle_id'] = "{$vehicle->fleet_code} is archived.";
        } elseif ($conflict = $this->conflicts($vehicle, $pickup, $return, $exceptRentalId)->first()) {
            $errors['vehicle_id'] = $this->conflictMessage($vehicle, $conflict);
        } elseif (in_array($vehicle->status, [VehicleStatus::Maintenance, VehicleStatus::OutOfService], true)) {
            $warnings[] = "{$vehicle->fleet_code} is currently ".str_replace('-', ' ', $vehicle->status->value).'.';
        }

        array_push($warnings, ...$this->documentWarnings($vehicle, $return));

        // ---- Customer and driver ----
        if ($customer->isBlocked()) {
            $errors['customer_id'] = "{$customer->customer_code} is blocked: {$customer->block_reason}";
        } elseif ($customer->isArchived()) {
            $errors['customer_id'] = "{$customer->customer_code} is archived.";
        }

        $driverCheck = (new DriverCheck($this->agency))->check($driver, $pickup);
        if (! $driverCheck['eligible']) {
            $problems = collect($driverCheck['checks'])->reject(fn ($check) => $check['ok'])->pluck('message');
            $errors['driver_id'] = "{$driver->full_name} cannot drive on {$pickup->format('d/m/Y')}: ".$problems->implode('; ').'.';
        }

        // ---- Price ----
        $days = $return->gt($pickup) ? RentalDays::between($pickup, $return) : 0;

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'days' => $days,
            'daily_price' => $vehicle->daily_price,
            'suggested_price' => Money::round($days * (float) $vehicle->daily_price),
            'deposit' => $vehicle->deposit,
        ];
    }

    /**
     * Reserved / active rentals of this car that overlap the period. An overdue car hasn't come back
     * and nobody knows when it will, so it stays blocked until it is returned.
     *
     * @return Collection<int, Rental>
     */
    public function conflicts(Vehicle $vehicle, CarbonImmutable $pickup, CarbonImmutable $return, ?int $exceptRentalId = null): Collection
    {
        $grace = $this->agency->late_grace_minutes;

        return Rental::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', RentalStatus::blocking())
            ->when($exceptRentalId, fn ($query) => $query->whereKeyNot($exceptRentalId))
            ->where('pickup_at', '<', $return->utc())
            ->get()
            ->filter(fn (Rental $rental) => $rental->isOverdue(null, $grace) || $rental->return_at->gt($pickup))
            ->values();
    }

    public function conflictMessage(Vehicle $vehicle, Rental $rental): string
    {
        $timezone = $this->agency->timezone;

        if ($rental->isOverdue(null, $this->agency->late_grace_minutes)) {
            return "{$vehicle->fleet_code} has not come back yet ({$rental->rental_code} is overdue).";
        }

        return "{$vehicle->fleet_code} is already booked from "
            .$rental->pickup_at->setTimezone($timezone)->format('d/m/Y H:i').' to '
            .$rental->return_at->setTimezone($timezone)->format('d/m/Y H:i')." ({$rental->rental_code}).";
    }

    /**
     * Missing documents, or documents expired / expiring before the return date.
     *
     * @return list<string>
     */
    public function documentWarnings(Vehicle $vehicle, CarbonImmutable $return): array
    {
        $overview = new DocumentOverview($this->agency);
        $returnDate = $return->toDateString();
        $warnings = [];

        foreach ($overview->rows($vehicle->loadMissing('documents')) as $row) {
            $label = $row['type']->label();
            $document = $row['current'];

            if ($row['status'] === DocumentStatus::Missing) {
                $warnings[] = "{$vehicle->fleet_code}: no {$label} recorded.";
            } elseif ($row['status'] === DocumentStatus::Expired) {
                $warnings[] = "{$vehicle->fleet_code}: {$label} expired on {$document->expiry_date->format('d/m/Y')}.";
            } elseif ($document->expiry_date && $document->expiry_date->toDateString() < $returnDate) {
                $warnings[] = "{$vehicle->fleet_code}: {$label} expires on {$document->expiry_date->format('d/m/Y')}, before the return.";
            }
        }

        return $warnings;
    }
}
