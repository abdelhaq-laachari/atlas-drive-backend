<?php

namespace Database\Seeders;

use App\Enums\ChargeKind;
use App\Enums\DamageStage;
use App\Enums\DamageStatus;
use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\RentalDamage;
use App\Models\RentalPayment;
use App\Models\Vehicle;
use App\Support\Money;
use App\Support\RentalDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample rentals relative to today (like the Vue prototype's sampleRentals.js):
 *   today: 2 pickups (R-0006 at 17:00 not yet handed over, R-0003 handed over this morning),
 *          1 return due at 18:00 (R-0004), 1 already returned this morning (R-0002)
 *   overdue: R-0005 (V-005) was due back yesterday
 *   upcoming: R-0007 in 2 days
 *   history: R-0001 completed, R-0008 cancelled
 */
class RentalSeeder extends Seeder
{
    private Agency $agency;

    private CarbonImmutable $today;

    public function run(Agency $agency): void
    {
        $this->agency = $agency;
        $this->today = CarbonImmutable::now($agency->timezone)->startOfDay();

        // [car, customer, status, pickup [days, time], return [days, time], paid share, deposit method]
        $this->rental(1, 1, RentalStatus::Completed, [-20, '08:00'], [-16, '08:00'], 1, 'cash', returned: [-16, '08:00']);
        $this->rental(4, 8, RentalStatus::Completed, [-3, '10:00'], [0, '10:00'], 1, 'cash', returned: [0, '10:15']);
        $this->rental(6, 12, RentalStatus::Active, [0, '09:00'], [7, '09:00'], 0.5, 'card');
        $this->rental(7, 4, RentalStatus::Active, [-2, '18:00'], [0, '18:00'], 1, 'cheque');
        $this->rental(5, 5, RentalStatus::Active, [-6, '10:00'], [-1, '10:00'], 0.6, 'cheque', notes: 'Customer not reachable by phone since the return date.');
        $this->rental(3, 2, RentalStatus::Reserved, [0, '17:00'], [3, '17:00'], 0, 'cash');
        $this->rental(8, 1, RentalStatus::Reserved, [2, '10:00'], [6, '10:00'], 0.3, 'card');
        $this->rental(10, 8, RentalStatus::Cancelled, [-10, '10:00'], [-7, '10:00'], 0, 'cash', notes: 'Cancelled by the customer two days before pickup.');

        // Cars follow their rentals (V-009 stays in maintenance)
        Vehicle::withoutGlobalScopes()->where('agency_id', $agency->id)->get()->each->syncStatusWithRentals();
    }

    /**
     * @param  array{int, string}  $pickup  [days from today, time]
     * @param  array{int, string}  $return
     * @param  array{int, string}|null  $returned
     */
    private function rental(int $carNumber, int $customerNumber, RentalStatus $status, array $pickup, array $return, float $paidShare, string $depositMethod, ?array $returned = null, ?string $notes = null): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('fleet_number', $carNumber)->firstOrFail();
        $customer = Customer::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('customer_number', $customerNumber)->firstOrFail();
        $pickupAt = $this->at($pickup);
        $returnAt = $this->at($return);
        $days = RentalDays::between($pickupAt, $returnAt);
        $price = $days * (float) $vehicle->daily_price;

        $rental = new Rental;
        $rental->agency_id = $this->agency->id; // seeders run with nobody logged in
        $rental->vehicle()->associate($vehicle);
        $rental->customer()->associate($customer);
        $rental->driver()->associate($customer);
        $rental->status = $status;
        $rental->pickup_at = $pickupAt;
        $rental->return_at = $returnAt;
        $rental->days = $days;
        $rental->daily_price = $vehicle->daily_price;
        $rental->deposit_amount = $status === RentalStatus::Cancelled ? 0 : $vehicle->deposit;
        $rental->deposit_method = $status === RentalStatus::Cancelled ? null : $depositMethod;
        $rental->deposit_reference = in_array($depositMethod, ['card', 'cheque'], true) ? 'SAMPLE-'.strtoupper($depositMethod).'-'.$carNumber : null;
        $rental->notes = $notes;

        if (in_array($status, [RentalStatus::Active, RentalStatus::Completed], true)) {
            $rental->handed_over_at = $pickupAt;
            $rental->handover_mileage = $vehicle->mileage - ($status === RentalStatus::Completed ? 900 : 0);
            $rental->handover_fuel = 4;
        }
        if ($status === RentalStatus::Completed) {
            $rental->returned_at = $this->at($returned);
            $rental->return_mileage = $vehicle->mileage;
            $rental->return_fuel = 4;
            $rental->deposit_returned_at = $this->at($returned);
        }
        if ($status === RentalStatus::Cancelled) {
            $rental->cancelled_at = $pickupAt->subDays(2)->setTime(15, 30);
            $rental->cancel_reason = $notes;
        }
        $rental->save();

        $rental->addCharge(
            ChargeKind::Rental,
            "Rental {$days} ".($days === 1 ? 'day' : 'days')." x {$vehicle->daily_price} MAD",
            $price,
            $status === RentalStatus::Cancelled ? 0 : $price,
            $status === RentalStatus::Cancelled ? 'Reservation cancelled' : null,
        );

        if ($paidShare > 0) {
            $payment = new RentalPayment;
            $payment->agency_id = $this->agency->id;
            $payment->rental()->associate($rental);
            $payment->amount = Money::round(round($price * $paidShare));
            $payment->method = $depositMethod === 'card' ? PaymentMethod::Card : PaymentMethod::Cash;
            $payment->reference = $depositMethod === 'card' ? 'SAMPLE-AUTH-'.$carNumber : null;
            $payment->paid_at = $pickupAt->subDays(3);
            $payment->save();
        }

        // Cars out now carry their open damage on the rental (frozen copy, like at a real hand-over)
        if ($status === RentalStatus::Active) {
            $vehicle->damages()->where('status', DamageStatus::Open)->get()
                ->each(fn ($damage) => RentalDamage::freeze($rental, $damage, DamageStage::Handover, known: true));
        }
    }

    /**
     * @param  array{int, string}  $when  [days from today, 'HH:MM'] in the agency's time
     */
    private function at(array $when): CarbonImmutable
    {
        [$days, $time] = $when;
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->today->addDays($days)->setTime($hour, $minute);
    }
}
