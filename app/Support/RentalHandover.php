<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\Rental;
use Carbon\CarbonImmutable;

/**
 * Can the car leave now? Used by the hand-over page (pre-check) and by the hand-over itself.
 * Errors block the hand-over; warnings are shown to staff.
 */
class RentalHandover
{
    public function __construct(private Agency $agency) {}

    /**
     * @return array{errors: array<string, string>, warnings: list<string>}
     */
    public function check(Rental $rental, CarbonImmutable $at): array
    {
        $errors = [];
        $warnings = [];
        $vehicle = $rental->vehicle;
        $code = $vehicle->fleet_code;

        if ($rental->status !== RentalStatus::Reserved) {
            $errors['rental'] = "{$rental->rental_code} is {$rental->status->value}: only a reservation can be handed over.";
        }

        // ---- The car ----
        $stillOut = Rental::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', RentalStatus::Active)
            ->whereKeyNot($rental->id)
            ->first();

        if ($vehicle->isArchived()) {
            $errors['vehicle'] = "{$code} is archived.";
        } elseif ($stillOut) {
            $due = $stillOut->return_at->setTimezone($this->agency->timezone)->format('d/m/Y H:i');
            $errors['vehicle'] = "{$code} is still out with {$stillOut->rental_code} (due back {$due}).";
        } elseif (in_array($vehicle->status, [VehicleStatus::Maintenance, VehicleStatus::OutOfService], true)) {
            $errors['vehicle'] = "{$code} is ".str_replace('-', ' ', $vehicle->status->value).'. Mark it available first.';
        }

        // ---- Papers: driving without insurance or technical inspection is illegal ----
        $overview = new DocumentOverview($this->agency);
        $returnDate = $rental->return_at->setTimezone($this->agency->timezone)->toDateString();
        $blockingPapers = [];

        foreach ($overview->rows($vehicle->loadMissing('documents')) as $row) {
            $label = $row['type']->label();
            $document = $row['current'];
            $problem = match ($row['status']) {
                DocumentStatus::Missing => "no {$label} recorded",
                DocumentStatus::Expired => "{$label} expired on {$document->expiry_date->format('d/m/Y')}",
                default => null,
            };

            if ($problem && $row['type']->isRequiredToDrive()) {
                $blockingPapers[] = $problem;
            } elseif ($problem) {
                $warnings[] = "{$code}: {$problem}.";
            } elseif ($document?->expiry_date && $document->expiry_date->toDateString() < $returnDate) {
                $warnings[] = "{$code}: {$label} expires on {$document->expiry_date->format('d/m/Y')}, before the return.";
            }
        }

        if ($blockingPapers) {
            $errors['documents'] = "{$code} can't leave: ".implode('; ', $blockingPapers).'.';
        }

        // ---- Customer and driver (things may have changed since the booking) ----
        $customer = $rental->customer;
        if ($customer->isBlocked()) {
            $errors['customer'] = "{$customer->customer_code} is blocked: {$customer->block_reason}";
        }

        $driverCheck = (new DriverCheck($this->agency))->check($rental->driver, $at);
        if (! $driverCheck['eligible']) {
            $problems = collect($driverCheck['checks'])->reject(fn ($check) => $check['ok'])->pluck('message');
            $errors['driver'] = "{$rental->driver->full_name} cannot drive: ".$problems->implode('; ').'.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
