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

class RentalBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Vehicle $car;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 Oct 2026, 12:00 in Casablanca
        $this->travelTo(now()->parse('2026-10-21 12:00:00', 'Africa/Casablanca'));

        $this->staff = User::factory()->create();
        $this->car = Vehicle::factory()->for($this->staff->agency)->create(['daily_price' => 270, 'deposit' => 3000]);
        $this->customer = Customer::factory()->for($this->staff->agency)->create(['first_name' => 'Amine', 'last_name' => 'Kettani']);

        // Valid papers, so only the tests about documents see document warnings
        foreach (DocumentType::cases() as $type) {
            VehicleDocument::factory()->for($this->car)->create([
                'type' => $type,
                'issue_date' => '2026-01-01',
                'expiry_date' => $type->hasExpiry() ? '2027-06-30' : null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingForm(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => '2026-11-10 10:00',
            'return_at' => '2026-11-13 10:00',
            'deposit_method' => 'cash',
        ], $overrides);
    }

    private function book(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/rentals', $this->bookingForm($overrides));
    }

    private function existingRental(string $pickupLocal, string $returnLocal, RentalStatus $status = RentalStatus::Reserved): Rental
    {
        return Rental::factory()->for($this->staff->agency)->status($status)->create([
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => now()->parse($pickupLocal, 'Africa/Casablanca'),
            'return_at' => now()->parse($returnLocal, 'Africa/Casablanca'),
        ]);
    }

    // ---- Quote ----

    public function test_a_quote_shows_days_price_and_deposit_without_saving(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/rentals/quote', $this->bookingForm(['return_at' => '2026-11-13 11:00']))
            ->assertOk()
            ->assertJsonPath('data.can_book', true)
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.daily_price', '270.00')
            ->assertJsonPath('data.suggested_price', '1080.00')
            ->assertJsonPath('data.deposit', '3000.00')
            ->assertJsonPath('data.warnings', []);

        $this->assertSame(0, Rental::count());
    }

    public function test_a_quote_lists_the_problems_instead_of_refusing(): void
    {
        $this->existingRental('2026-11-09 10:00', '2026-11-11 10:00');

        $this->actingAs($this->staff)
            ->postJson('/api/rentals/quote', $this->bookingForm())
            ->assertOk()
            ->assertJsonPath('data.can_book', false)
            ->assertJsonPath('data.errors.vehicle_id', 'V-001 is already booked from 09/11/2026 10:00 to 11/11/2026 10:00 (R-0001).');
    }

    // ---- Booking ----

    public function test_staff_can_book_a_car(): void
    {
        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.rental_code', 'R-0001')
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.vehicle.fleet_code', 'V-001')
            ->assertJsonPath('data.customer.full_name', 'Amine Kettani')
            ->assertJsonPath('data.driver.id', $this->customer->id)
            ->assertJsonPath('data.pickup_at', now()->parse('2026-11-10 10:00', 'Africa/Casablanca')->toIso8601String())
            ->assertJsonPath('data.return_at', now()->parse('2026-11-13 10:00', 'Africa/Casablanca')->toIso8601String())
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.total', '810.00')
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.balance', '810.00')
            ->assertJsonPath('data.charges.0.kind', 'rental')
            ->assertJsonPath('data.charges.0.label', 'Rental 3 days x 270.00 MAD')
            ->assertJsonPath('data.charges.0.decision', 'charge')
            ->assertJsonPath('data.deposit.amount', '3000.00')
            ->assertJsonPath('data.deposit.receipt_code', 'DEP-0001');

        $this->assertSame(VehicleStatus::Reserved, $this->car->fresh()->status);
    }

    public function test_times_are_agency_local_in_and_out_and_utc_in_the_database(): void
    {
        // An agency in a timezone ahead of UTC (Africa/Lagos = UTC+1 all year)
        $this->staff->agency->update(['timezone' => 'Africa/Lagos']);

        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.pickup_at', '2026-11-10T10:00:00+01:00');

        $this->assertSame('2026-11-10 09:00:00', Rental::sole()->getRawOriginal('pickup_at'));
    }

    public function test_a_car_out_on_another_rental_keeps_its_status(): void
    {
        $this->car->forceFill(['status' => VehicleStatus::Rented])->save();
        $this->existingRental('2026-10-20 10:00', '2026-10-25 10:00', RentalStatus::Active);

        $this->book()->assertCreated();

        $this->assertSame(VehicleStatus::Rented, $this->car->fresh()->status);
    }

    public function test_someone_else_can_drive(): void
    {
        $driver = Customer::factory()->for($this->staff->agency)->create(['first_name' => 'Sara']);

        $this->book(['driver_id' => $driver->id])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.driver.full_name', $driver->full_name);
    }

    public function test_rental_codes_and_deposit_receipts_are_numbered_per_agency(): void
    {
        Rental::factory()->create(['deposit_amount' => 3000]); // another agency

        $this->book()->assertJsonPath('data.rental_code', 'R-0001')->assertJsonPath('data.deposit.receipt_code', 'DEP-0001');
        $this->book(['pickup_at' => '2026-11-20 10:00', 'return_at' => '2026-11-22 10:00'])
            ->assertJsonPath('data.rental_code', 'R-0002')
            ->assertJsonPath('data.deposit.receipt_code', 'DEP-0002');
        $this->book(['pickup_at' => '2026-12-01 10:00', 'return_at' => '2026-12-02 10:00', 'deposit_amount' => 0, 'deposit_method' => null])
            ->assertJsonPath('data.rental_code', 'R-0003')
            ->assertJsonPath('data.deposit.receipt_code', null);
    }

    public function test_footprints_record_who_booked(): void
    {
        $this->book();

        $log = ActivityLog::where('subject_type', 'rental')->sole();
        $this->assertSame('created', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame('R-0001 V-001', $log->subject_label);
    }

    // ---- Price, deposit, payment ----

    public function test_a_lower_price_needs_a_reason(): void
    {
        $this->book(['price' => 700])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_reason' => 'Give a reason for the lower price.']);

        $this->book(['price' => 700, 'price_reason' => 'Loyal customer'])
            ->assertCreated()
            ->assertJsonPath('data.total', '700.00')
            ->assertJsonPath('data.charges.0.suggested', '810.00')
            ->assertJsonPath('data.charges.0.amount', '700.00')
            ->assertJsonPath('data.charges.0.decision', 'reduce')
            ->assertJsonPath('data.charges.0.reason', 'Loyal customer');
    }

    public function test_a_free_rental_is_a_waive(): void
    {
        $this->book(['price' => 0, 'price_reason' => 'Family'])
            ->assertCreated()
            ->assertJsonPath('data.charges.0.decision', 'waive');
    }

    public function test_the_deposit_needs_a_method_and_a_reference_for_cheques_cards_transfers(): void
    {
        $this->book(['deposit_method' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_method');

        $this->book(['deposit_method' => 'cheque'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_reference');

        $this->book(['deposit_method' => 'cheque', 'deposit_reference' => 'CHQ-12345', 'deposit_amount' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.deposit.amount', '5000.00')
            ->assertJsonPath('data.deposit.method', 'cheque')
            ->assertJsonPath('data.deposit.reference', 'CHQ-12345');
    }

    public function test_a_payment_can_be_received_at_booking(): void
    {
        $this->book(['payment' => ['amount' => 300, 'method' => 'card', 'reference' => 'AUTH-998']])
            ->assertCreated()
            ->assertJsonPath('data.paid', '300.00')
            ->assertJsonPath('data.balance', '510.00')
            ->assertJsonPath('data.payments.0.method', 'card')
            ->assertJsonPath('data.payments.0.reference', 'AUTH-998');

        $this->book(['pickup_at' => '2026-11-20 10:00', 'return_at' => '2026-11-22 10:00', 'payment' => ['amount' => 100.5, 'method' => 'cash']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment.amount');
    }

    // ---- Availability ----

    public function test_a_car_cannot_be_booked_twice_for_overlapping_dates(): void
    {
        $this->existingRental('2026-11-12 10:00', '2026-11-15 10:00');

        $this->book()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id' => 'V-001 is already booked from 12/11/2026 10:00 to 15/11/2026 10:00 (R-0001).']);
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $this->existingRental('2026-11-13 10:00', '2026-11-15 10:00'); // starts when ours ends

        $this->book()->assertCreated();
    }

    public function test_cancelled_and_completed_rentals_do_not_block_the_car(): void
    {
        $this->existingRental('2026-11-10 10:00', '2026-11-13 10:00', RentalStatus::Cancelled);
        $this->existingRental('2026-11-09 10:00', '2026-11-12 10:00', RentalStatus::Completed);

        $this->book()->assertCreated();
    }

    public function test_an_overdue_car_is_blocked_until_it_comes_back(): void
    {
        // Was due back yesterday and hasn't come back
        $this->existingRental('2026-10-15 10:00', '2026-10-20 10:00', RentalStatus::Active);

        $this->book(['pickup_at' => '2026-12-20 10:00', 'return_at' => '2026-12-22 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id' => 'V-001 has not come back yet (R-0001 is overdue).']);
    }

    public function test_an_archived_car_cannot_be_booked(): void
    {
        $this->car->forceFill(['archived_at' => now(), 'archive_reason' => 'Sold'])->save();

        $this->book()->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
    }

    public function test_a_car_in_maintenance_can_be_booked_with_a_warning(): void
    {
        $this->car->forceFill(['status' => VehicleStatus::Maintenance])->save();

        $this->book()
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0', 'V-001 is currently maintenance.');

        $this->assertSame(VehicleStatus::Maintenance, $this->car->fresh()->status);
    }

    public function test_expired_or_expiring_documents_give_warnings_at_booking(): void
    {
        // Newer documents (they become the current ones): one expired, one expiring during the rental
        VehicleDocument::factory()->for($this->car)->create(['issue_date' => '2026-02-01', 'expiry_date' => '2026-10-19']);
        VehicleDocument::factory()->for($this->car)->type(DocumentType::Inspection)
            ->create(['issue_date' => '2026-03-01', 'expiry_date' => '2026-11-11']);

        $this->book()
            ->assertCreated()
            ->assertJsonPath('meta.warnings', [
                'V-001: Insurance expired on 19/10/2026.',
                'V-001: Technical inspection expires on 11/11/2026, before the return.',
            ]);
    }

    // ---- Dates, people, agency ----

    public function test_dates_must_be_valid_and_in_the_future(): void
    {
        $this->book(['pickup_at' => '2026-10-20 10:00', 'return_at' => '2026-10-22 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pickup_at' => 'The pickup cannot be in the past.']);

        $this->book(['return_at' => '2026-11-09 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['return_at' => 'The return must be after the pickup.']);

        $this->book(['pickup_at' => '10/11/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_at');
    }

    public function test_a_walk_in_rental_can_start_today(): void
    {
        $this->book(['pickup_at' => '2026-10-21 12:00', 'return_at' => '2026-10-23 12:00'])->assertCreated();
    }

    public function test_the_driver_must_meet_the_age_and_licence_rules_on_the_pickup_date(): void
    {
        $young = Customer::factory()->for($this->staff->agency)->create([
            'first_name' => 'Hamza', 'last_name' => 'Berrada', 'birth_date' => '2005-11-15', 'licence_issue_date' => '2025-01-01',
        ]);

        $this->book(['driver_id' => $young->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['driver_id' => 'Hamza Berrada cannot drive on 10/11/2026: 20 years old (minimum 21); Licence held for 1 year (minimum 2).']);
    }

    public function test_a_blocked_customer_cannot_book(): void
    {
        $this->customer->forceFill(['blocked_at' => now(), 'block_reason' => 'Unpaid damage'])->save();

        $this->book()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id' => 'C-001 is blocked: Unpaid damage']);
    }

    public function test_cars_and_customers_of_another_agency_are_not_found(): void
    {
        $otherCar = Vehicle::factory()->create();
        $otherCustomer = Customer::factory()->create();

        $this->book(['vehicle_id' => $otherCar->id, 'customer_id' => $otherCustomer->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id' => 'Not found.', 'customer_id' => 'Not found.']);
    }

    public function test_status_and_totals_cannot_be_sent_in_the_form(): void
    {
        $this->book(['status' => 'completed', 'days' => 1, 'daily_price' => 1])
            ->assertCreated()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.daily_price', '270.00');
    }

    // ---- Reading ----

    public function test_a_rental_of_another_agency_is_not_found(): void
    {
        $other = Rental::factory()->create();

        $this->actingAs($this->staff)->getJson("/api/rentals/{$other->id}")->assertNotFound();
    }

    public function test_the_list_shows_the_agency_rentals_newest_pickup_first(): void
    {
        Rental::factory()->create(); // another agency (created before logging in)
        $this->book();
        $this->book(['pickup_at' => '2026-11-20 10:00', 'return_at' => '2026-11-22 10:00']);

        $this->actingAs($this->staff)
            ->getJson('/api/rentals')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rental_code', 'R-0002');
    }
}
