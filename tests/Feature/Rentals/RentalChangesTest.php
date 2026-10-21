<?php

namespace Tests\Feature\Rentals;

use App\Enums\DocumentType;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Before pickup: cancel, extend, payments and refunds.
 */
class RentalChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Vehicle $car;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-10-21 12:00:00', 'Africa/Casablanca'));

        $this->staff = User::factory()->create();
        $this->car = Vehicle::factory()->for($this->staff->agency)->create(['daily_price' => 270, 'deposit' => 3000]);
        $this->customer = Customer::factory()->for($this->staff->agency)->create();

        foreach (DocumentType::cases() as $type) {
            VehicleDocument::factory()->for($this->car)->create([
                'type' => $type, 'issue_date' => '2026-01-01', 'expiry_date' => $type->hasExpiry() ? '2027-06-30' : null,
            ]);
        }
    }

    /**
     * Book through the API, like the Vue form: 3 days x 270 = 810 MAD, deposit 3000 cash.
     */
    private function book(array $overrides = []): Rental
    {
        $this->actingAs($this->staff)->postJson('/api/rentals', array_merge([
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => '2026-11-10 10:00',
            'return_at' => '2026-11-13 10:00',
            'deposit_method' => 'cash',
        ], $overrides))->assertCreated();

        return Rental::latest('id')->first();
    }

    private function cancel(Rental $rental, array $data = ['reason' => 'Customer changed plans']): TestResponse
    {
        return $this->postJson("/api/rentals/{$rental->id}/cancel", $data);
    }

    private function extend(Rental $rental, array $data): TestResponse
    {
        return $this->postJson("/api/rentals/{$rental->id}/extend", $data);
    }

    private function pay(Rental $rental, array $data): TestResponse
    {
        return $this->postJson("/api/rentals/{$rental->id}/payments", $data);
    }

    // ---- Cancel ----

    public function test_a_reservation_can_be_cancelled_and_the_car_is_freed(): void
    {
        $rental = $this->book();

        $this->cancel($rental)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Customer changed plans')
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.charges.0.decision', 'waive')
            ->assertJsonPath('data.charges.0.suggested', '810.00')
            ->assertJsonPath('data.deposit.returned_at', now()->toIso8601String());

        $this->assertSame(VehicleStatus::Available, $this->car->fresh()->status);
        $this->assertSame('cancelled', ActivityLog::where('subject_type', 'rental')->latest('id')->first()->action);
    }

    public function test_a_cancellation_fee_can_be_charged(): void
    {
        $rental = $this->book();

        $this->cancel($rental, ['reason' => 'Cancelled the day before', 'fee' => 200])
            ->assertOk()
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.charges.1.kind', 'cancellation')
            ->assertJsonPath('data.charges.1.amount', '200.00');
    }

    public function test_the_car_stays_reserved_when_another_booking_is_waiting(): void
    {
        $first = $this->book();
        $this->book(['pickup_at' => '2026-11-20 10:00', 'return_at' => '2026-11-22 10:00']);

        $this->cancel($first)->assertOk();

        $this->assertSame(VehicleStatus::Reserved, $this->car->fresh()->status);
    }

    public function test_a_car_in_maintenance_stays_in_maintenance(): void
    {
        $rental = $this->book();
        $this->car->forceFill(['status' => VehicleStatus::Maintenance])->save();

        $this->cancel($rental)->assertOk();

        $this->assertSame(VehicleStatus::Maintenance, $this->car->fresh()->status);
    }

    public function test_cancelling_needs_a_reason_and_only_works_for_reservations(): void
    {
        $rental = $this->book();

        $this->cancel($rental, [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $rental->forceFill(['status' => RentalStatus::Active])->save();
        $this->cancel($rental)->assertConflict();

        $rental->forceFill(['status' => RentalStatus::Cancelled])->save();
        $this->cancel($rental)->assertConflict();
    }

    public function test_money_paid_for_a_cancelled_booking_can_be_refunded(): void
    {
        $rental = $this->book(['payment' => ['amount' => 300, 'method' => 'cash']]);

        $this->cancel($rental)->assertJsonPath('data.balance', '-300.00'); // the agency owes 300

        $this->pay($rental, ['amount' => 100, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'This rental is cancelled: only refunds are possible.']);

        $this->pay($rental, ['amount' => 400, 'method' => 'cash', 'refund' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => "You can't refund more than what was paid (300.00 MAD)."]);

        $this->pay($rental, ['amount' => 300, 'method' => 'cash', 'refund' => true])
            ->assertCreated()
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.payments.1.amount', '-300.00');
    }

    // ---- Extend ----

    public function test_a_reservation_can_be_extended_with_the_suggested_price(): void
    {
        $rental = $this->book();

        $this->extend($rental, ['return_at' => '2026-11-15 10:00'])
            ->assertOk()
            ->assertJsonPath('data.days', 5)
            ->assertJsonPath('data.return_at', now()->parse('2026-11-15 10:00', 'Africa/Casablanca')->toIso8601String())
            ->assertJsonPath('data.total', '1350.00')
            ->assertJsonPath('data.charges.1.kind', 'extension')
            ->assertJsonPath('data.charges.1.label', 'Extension +2 days (until 15/11/2026 10:00)')
            ->assertJsonPath('data.charges.1.suggested', '540.00')
            ->assertJsonPath('data.meta', null);

        $log = ActivityLog::where('subject_type', 'rental')->latest('id')->first();
        $this->assertSame('extended', $log->action);
        $this->assertSame(['days', 'return_at'], array_keys($log->diff));
        $this->assertNotEquals($log->diff['return_at']['old'], $log->diff['return_at']['new']);
    }

    public function test_coming_back_later_in_the_day_counts_one_more_day(): void
    {
        $rental = $this->book();

        $this->extend($rental, ['return_at' => '2026-11-13 12:00'])
            ->assertOk()
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.charges.1.suggested', '270.00');
    }

    public function test_the_extension_can_be_reduced_or_waived_with_a_reason(): void
    {
        $rental = $this->book();

        $this->extend($rental, ['return_at' => '2026-11-15 10:00', 'amount' => 300])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'Give a reason for charging less than suggested.']);

        $this->extend($rental, ['return_at' => '2026-11-15 10:00', 'amount' => 0, 'reason' => 'Loyal customer'])
            ->assertOk()
            ->assertJsonPath('data.total', '810.00')
            ->assertJsonPath('data.charges.1.decision', 'waive')
            ->assertJsonPath('data.charges.1.reason', 'Loyal customer');
    }

    public function test_the_new_return_must_be_later_and_in_the_future(): void
    {
        $rental = $this->book();

        $this->extend($rental, ['return_at' => '2026-11-12 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at' => 'The new return must be later than the current return (13/11/2026 10:00).']);

        // An overdue rental (due back yesterday) can only be extended into the future
        $rental->forceFill([
            'status' => RentalStatus::Active,
            'pickup_at' => now()->subDays(5),
            'return_at' => now()->subDay(),
        ])->save();

        $this->extend($rental, ['return_at' => now()->subHours(2)->setTimezone('Africa/Casablanca')->format('Y-m-d H:i')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at' => 'The new return must be in the future.']);

        $this->extend($rental, ['return_at' => '2026-10-23 10:00'])->assertOk();
    }

    public function test_an_extension_cannot_overlap_the_next_booking(): void
    {
        $rental = $this->book();
        $this->book(['pickup_at' => '2026-11-14 10:00', 'return_at' => '2026-11-16 10:00']);

        $this->extend($rental, ['return_at' => '2026-11-15 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at' => 'V-001 is already booked from 14/11/2026 10:00 to 16/11/2026 10:00 (R-0002).']);

        $this->extend($rental, ['return_at' => '2026-11-14 10:00'])->assertOk(); // back-to-back is fine
    }

    public function test_finished_or_cancelled_rentals_cannot_be_extended(): void
    {
        $rental = $this->book();

        foreach ([RentalStatus::Completed, RentalStatus::Cancelled] as $status) {
            $rental->forceFill(['status' => $status])->save();
            $this->extend($rental, ['return_at' => '2026-11-15 10:00'])->assertConflict();
        }
    }

    // ---- Payments ----

    public function test_payments_reduce_the_balance(): void
    {
        $rental = $this->book();

        $this->pay($rental, ['amount' => 500, 'method' => 'cheque', 'reference' => 'CHQ-77'])
            ->assertCreated()
            ->assertJsonPath('data.paid', '500.00')
            ->assertJsonPath('data.balance', '310.00')
            ->assertJsonPath('data.payments.0.reference', 'CHQ-77');

        $this->assertSame('created', ActivityLog::where('subject_type', 'rental_payment')->sole()->action);
    }

    public function test_payment_rules(): void
    {
        $rental = $this->book();

        $this->pay($rental, ['amount' => 0, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($rental, ['amount' => 100.5, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay($rental, ['amount' => 100, 'method' => 'deposit'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->pay($rental, ['amount' => 100, 'method' => 'transfer'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->pay($rental, ['amount' => 900, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => "That's more than what is due (810.00 MAD)."]);
    }

    public function test_another_agency_rental_cannot_be_changed(): void
    {
        $other = Rental::factory()->create();

        $this->actingAs($this->staff);

        $this->cancel($other)->assertNotFound();
        $this->extend($other, ['return_at' => '2026-12-01 10:00'])->assertNotFound();
        $this->pay($other, ['amount' => 100, 'method' => 'cash'])->assertNotFound();
    }
}
