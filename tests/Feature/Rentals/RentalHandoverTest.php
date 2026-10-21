<?php

namespace Tests\Feature\Rentals;

use App\Enums\DamageStatus;
use App\Enums\DocumentType;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RentalHandoverTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Vehicle $car;

    private Customer $customer;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        // Today 10 Nov 2026, 10:05 — the customer picks up the car booked for 10:00
        $this->travelTo(now()->parse('2026-11-10 10:05:00', 'Africa/Casablanca'));

        $this->staff = User::factory()->create();
        $this->car = Vehicle::factory()->for($this->staff->agency)->create(['daily_price' => 270, 'mileage' => 61200]);
        $this->customer = Customer::factory()->for($this->staff->agency)->create(['first_name' => 'Amine', 'last_name' => 'Kettani']);

        foreach (DocumentType::cases() as $type) {
            VehicleDocument::factory()->for($this->car)->create([
                'type' => $type, 'issue_date' => '2026-01-01', 'expiry_date' => $type->hasExpiry() ? '2027-06-30' : null,
            ]);
        }

        $this->actingAs($this->staff)->postJson('/api/rentals', [
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => '2026-11-10 10:00',
            'return_at' => '2026-11-13 10:00',
            'deposit_method' => 'cash',
        ])->assertCreated();

        $this->rental = Rental::sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function handoverForm(array $overrides = []): array
    {
        return array_merge(['mileage' => 61250, 'fuel' => 4, 'remarks' => 'Spare tyre present.'], $overrides);
    }

    private function handOver(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/rentals/{$this->rental->id}/handover", $this->handoverForm($overrides));
    }

    private function scratch(array $attributes = []): VehicleDamage
    {
        return VehicleDamage::factory()->for($this->car)->create($attributes);
    }

    // ---- Pre-check ----

    public function test_the_pre_check_shows_the_car_km_and_its_open_damage(): void
    {
        $open = $this->scratch(['area' => 'Rear bumper']);
        VehicleDamage::factory()->for($this->car)->repaired()->create(); // not on the drawing

        $this->getJson("/api/rentals/{$this->rental->id}/handover")
            ->assertOk()
            ->assertJsonPath('data.can_hand_over', true)
            ->assertJsonPath('data.errors', [])
            ->assertJsonPath('data.current_mileage', 61200)
            ->assertJsonCount(1, 'data.open_damages')
            ->assertJsonPath('data.open_damages.0.id', $open->id);
    }

    public function test_the_pre_check_lists_what_blocks_the_car(): void
    {
        VehicleDocument::factory()->for($this->car)->create(['issue_date' => '2026-02-01', 'expiry_date' => '2026-11-01']);

        $this->getJson("/api/rentals/{$this->rental->id}/handover")
            ->assertOk()
            ->assertJsonPath('data.can_hand_over', false)
            ->assertJsonPath('data.errors.documents', "V-001 can't leave: Insurance expired on 01/11/2026.");
    }

    // ---- Hand-over ----

    public function test_the_car_is_handed_over(): void
    {
        $this->handOver()
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.handover.at', now()->setTimezone('Africa/Casablanca')->startOfMinute()->toIso8601String())
            ->assertJsonPath('data.handover.mileage', 61250)
            ->assertJsonPath('data.handover.fuel', 4)
            ->assertJsonPath('data.handover.remarks', 'Spare tyre present.')
            ->assertJsonPath('data.return', null);

        $this->car->refresh();
        $this->assertSame(VehicleStatus::Rented, $this->car->status);
        $this->assertSame(61250, $this->car->mileage);
        $this->assertSame('handed_over', ActivityLog::where('subject_type', 'rental')->latest('id')->first()->action);
    }

    public function test_a_hand_over_time_can_be_entered_but_not_in_the_future(): void
    {
        $this->handOver(['at' => '2026-11-10 11:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['at' => 'The hand-over time cannot be in the future.']);

        $this->handOver(['at' => '2026-11-10 09:50'])
            ->assertOk()
            ->assertJsonPath('data.handover.at', now()->parse('2026-11-10 09:50', 'Africa/Casablanca')->toIso8601String());
    }

    public function test_known_damage_is_copied_and_new_damage_goes_on_the_car(): void
    {
        $known = $this->scratch(['area' => 'Rear bumper']);

        $this->handOver(['new_damages' => [
            ['x' => 0.77, 'y' => 0.45, 'area' => 'Front right door', 'type' => 'dent', 'size' => 'medium', 'note' => 'Small dent'],
        ]])
            ->assertOk()
            ->assertJsonCount(2, 'data.damages')
            ->assertJsonPath('data.damages.0.vehicle_damage_id', $known->id)
            ->assertJsonPath('data.damages.0.known', true)
            ->assertJsonPath('data.damages.0.stage', 'handover')
            ->assertJsonPath('data.damages.1.area', 'Front right door')
            ->assertJsonPath('data.damages.1.known', false);

        $new = VehicleDamage::where('area', 'Front right door')->sole();
        $this->assertSame(DamageStatus::Open, $new->status);
        $this->assertSame('handover', $new->found_stage->value);
        $this->assertSame($this->rental->id, $new->rental_id);
    }

    public function test_the_rental_keeps_its_frozen_copy_when_the_car_is_repaired_later(): void
    {
        $known = $this->scratch(['area' => 'Rear bumper']);
        $this->handOver()->assertOk();

        $known->forceFill(['status' => DamageStatus::Repaired, 'repaired_at' => '2026-11-10', 'area' => 'Boot'])->save();

        $this->getJson("/api/rentals/{$this->rental->id}")
            ->assertJsonPath('data.damages.0.area', 'Rear bumper');
    }

    public function test_a_payment_can_be_received_at_hand_over(): void
    {
        $this->handOver(['payment' => ['amount' => 810, 'method' => 'card', 'reference' => 'AUTH-5']])
            ->assertOk()
            ->assertJsonPath('data.paid', '810.00')
            ->assertJsonPath('data.balance', '0.00');
    }

    public function test_the_payment_cannot_be_more_than_what_is_due(): void
    {
        $this->handOver(['payment' => ['amount' => 900, 'method' => 'cash']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.amount' => "That's more than what is due (810.00 MAD)."]);
    }

    public function test_warnings_for_papers_that_expire_before_the_return(): void
    {
        VehicleDocument::factory()->for($this->car)->type(DocumentType::Inspection)
            ->create(['issue_date' => '2026-03-01', 'expiry_date' => '2026-11-12']);

        $this->handOver()
            ->assertOk()
            ->assertJsonPath('meta.warnings.0', 'V-001: Technical inspection expires on 12/11/2026, before the return.');
    }

    // ---- What blocks the hand-over ----

    public function test_expired_or_missing_insurance_or_inspection_blocks_the_car(): void
    {
        VehicleDocument::factory()->for($this->car)->type(DocumentType::Inspection)
            ->create(['issue_date' => '2026-02-01', 'expiry_date' => '2026-11-09']);

        $this->handOver()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents' => "V-001 can't leave: Technical inspection expired on 09/11/2026."]);

        $this->assertSame(RentalStatus::Reserved, $this->rental->fresh()->status);
    }

    public function test_an_expired_vignette_only_warns(): void
    {
        VehicleDocument::factory()->for($this->car)->type(DocumentType::Vignette)
            ->create(['issue_date' => '2026-02-01', 'expiry_date' => '2026-11-09']);

        $this->handOver()
            ->assertOk()
            ->assertJsonPath('meta.warnings.0', 'V-001: Road tax (vignette) expired on 09/11/2026.');
    }

    public function test_the_car_cannot_leave_while_another_customer_still_has_it(): void
    {
        $this->car->forceFill(['status' => VehicleStatus::Rented])->save();
        Rental::factory()->for($this->staff->agency)->status(RentalStatus::Active)->create([
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => now()->subDays(5),
            'return_at' => now()->subHours(2), // late
        ]);

        $this->handOver()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle' => 'V-001 is still out with R-0002 (due back 10/11/2026 08:05).']);
    }

    public function test_a_car_in_maintenance_cannot_leave(): void
    {
        $this->car->forceFill(['status' => VehicleStatus::Maintenance])->save();

        $this->handOver()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle' => 'V-001 is maintenance. Mark it available first.']);
    }

    public function test_the_customer_or_driver_may_have_changed_since_the_booking(): void
    {
        $this->customer->forceFill(['blocked_at' => now(), 'block_reason' => 'Unpaid damage'])->save();

        $this->handOver()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer' => 'C-001 is blocked: Unpaid damage']);
    }

    public function test_km_and_fuel_are_checked(): void
    {
        $this->handOver(['mileage' => 60000, 'fuel' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'mileage' => "The km can't be lower than the car's current km (61200).",
                'fuel',
            ]);
    }

    public function test_damage_marks_are_checked(): void
    {
        $this->handOver(['new_damages' => [['x' => 2, 'y' => 0.5, 'area' => 'Spoiler', 'type' => 'scratch', 'size' => 'small']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_damages.0.x', 'new_damages.0.area']);
    }

    public function test_only_a_reservation_can_be_handed_over(): void
    {
        $this->handOver()->assertOk();

        $this->handOver()->assertConflict();
    }

    public function test_another_agency_rental_cannot_be_handed_over(): void
    {
        // Created with nobody logged in, otherwise the agency guard would put it in our agency
        $this->app['auth']->forgetGuards();
        $other = Rental::factory()->create();
        $this->actingAs($this->staff);

        $this->getJson("/api/rentals/{$other->id}/handover")->assertNotFound();
        $this->postJson("/api/rentals/{$other->id}/handover", $this->handoverForm())->assertNotFound();
    }
}
