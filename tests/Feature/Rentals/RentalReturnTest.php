<?php

namespace Tests\Feature\Rentals;

use App\Enums\DamageStage;
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
use App\Support\ReturnFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rental 10/11 10:00 → 13/11 10:00 (3 days x 270 = 810 MAD), deposit 3000 cash,
 * car: 250 km included per day (750 for 3 days), extra-km fee 1.50; handed over at 61250 km, fuel full.
 */
class RentalReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Vehicle $car;

    private Customer $customer;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-11-10 10:05:00', 'Africa/Casablanca'));

        $this->staff = User::factory()->create();
        $this->car = Vehicle::factory()->for($this->staff->agency)->create([
            'daily_price' => 270, 'deposit' => 3000, 'mileage' => 61200, 'included_km_per_day' => 250, 'extra_km_fee' => 1.5,
        ]);
        $this->customer = Customer::factory()->for($this->staff->agency)->create();

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

        $this->postJson("/api/rentals/{$this->rental->id}/handover", ['mileage' => 61250, 'fuel' => 4])->assertOk();
    }

    /**
     * Come back at a local time (the clock moves there).
     */
    private function backAt(string $local): void
    {
        $this->travelTo(now()->parse($local, 'Africa/Casablanca')->addMinutes(2));
    }

    private function giveBack(array $data): TestResponse
    {
        return $this->postJson("/api/rentals/{$this->rental->id}/return", $data);
    }

    // ---- Facts (no prices) ----

    public function test_an_on_time_return_has_nothing_to_decide(): void
    {
        $this->backAt('2026-11-13 10:30');

        $this->getJson("/api/rentals/{$this->rental->id}/return?at=2026-11-13%2010:30&mileage=61900&fuel=4")
            ->assertOk()
            ->assertJsonPath('data.can_return', true)
            ->assertJsonPath('data.facts.km.driven', 650)
            ->assertJsonPath('data.facts.km.included', 750)
            ->assertJsonPath('data.facts.km.over', 0)
            ->assertJsonPath('data.facts.late.minutes_late', 30)
            ->assertJsonPath('data.facts.late.beyond_grace', false)
            ->assertJsonPath('data.facts.late.extra_days', 0)
            ->assertJsonPath('data.facts.fuel.missing_quarters', 0)
            ->assertJsonPath('data.facts.suggested', null)
            ->assertJsonPath('data.balance', '810.00');
    }

    public function test_the_facts_show_km_over_lateness_and_missing_fuel_without_prices(): void
    {
        $this->backAt('2026-11-13 13:15');

        $this->getJson("/api/rentals/{$this->rental->id}/return?at=2026-11-13%2013:15&mileage=62120&fuel=2")
            ->assertOk()
            ->assertJsonPath('data.facts.km.driven', 870)
            ->assertJsonPath('data.facts.km.over', 120)
            ->assertJsonPath('data.facts.late.minutes_late', 195)
            ->assertJsonPath('data.facts.late.text', '3 h 15 late')
            ->assertJsonPath('data.facts.late.extra_days', 1)
            ->assertJsonPath('data.facts.fuel.missing_quarters', 2)
            ->assertJsonPath('data.facts.suggested', null);
    }

    public function test_unlimited_km_is_never_over(): void
    {
        $this->car->forceFill(['included_km_per_day' => null])->save();
        $this->backAt('2026-11-13 10:00');

        $this->getJson("/api/rentals/{$this->rental->id}/return?mileage=65000&fuel=4")
            ->assertJsonPath('data.facts.km.included', null)
            ->assertJsonPath('data.facts.km.over', 0);
    }

    public function test_an_early_return_shows_the_unused_days(): void
    {
        $this->backAt('2026-11-12 09:00');

        $this->getJson("/api/rentals/{$this->rental->id}/return?at=2026-11-12%2009:00&mileage=61500&fuel=4")
            ->assertJsonPath('data.facts.early.unused_days', 1);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function lateness(): array
    {
        return [
            'on time' => [0, 'on time'],
            'minutes' => [45, '45 min late'],
            'hours' => [200, '3 h 20 late'],
            'whole hours' => [180, '3 h late'],
            'a day and a half' => [2160, '1 day 12 h late'],
            'two days' => [2880, '2 days late'],
        ];
    }

    #[DataProvider('lateness')]
    public function test_lateness_in_words(int $minutes, string $text): void
    {
        $this->assertSame($text, ReturnFacts::latenessText($minutes));
    }

    // ---- Returning ----

    public function test_an_on_time_return_closes_the_rental_and_frees_the_car(): void
    {
        $this->backAt('2026-11-13 10:00');

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'remarks' => 'Clean car.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.return.mileage', 61900)
            ->assertJsonPath('data.return.fuel', 4)
            ->assertJsonPath('data.return.remarks', 'Clean car.')
            ->assertJsonCount(1, 'data.charges')
            ->assertJsonPath('data.balance', '810.00')
            ->assertJsonPath('data.deposit.kept', '0.00');

        $this->car->refresh();
        $this->assertSame(VehicleStatus::Available, $this->car->status);
        $this->assertSame(61900, $this->car->mileage);
        $this->assertNotNull($this->rental->fresh()->deposit_returned_at);
        $this->assertSame('returned', ActivityLog::where('subject_type', 'rental')->latest('id')->first()->action);
    }

    public function test_every_fact_that_happened_needs_a_decision(): void
    {
        $this->backAt('2026-11-13 13:15');

        $this->giveBack(['mileage' => 62120, 'fuel' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'charges.extra_km.amount' => 'Decide: charge an amount, or 0 for no charge.',
                'charges.late.amount',
                'charges.fuel.amount',
            ]);

        $this->assertSame(RentalStatus::Active, $this->rental->fresh()->status);
    }

    public function test_the_agent_charges_or_forgives_each_fact(): void
    {
        $this->backAt('2026-11-13 13:15');

        $this->giveBack([
            'at' => '2026-11-13 13:15',
            'mileage' => 62120,
            'fuel' => 2,
            'charges' => [
                'extra_km' => ['amount' => 200],
                'late' => ['amount' => 0, 'note' => 'Called ahead, loyal client'],
                'fuel' => ['amount' => 300],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.total', '1310.00')
            ->assertJsonPath('data.charges.1.kind', 'extra_km')
            ->assertJsonPath('data.charges.1.label', 'Extra km: 120 km over 750 included')
            ->assertJsonPath('data.charges.1.suggested', null)
            ->assertJsonPath('data.charges.1.decision', 'charge')
            ->assertJsonPath('data.charges.2.label', 'Late return: 3 h 15 late (1 extra day)')
            ->assertJsonPath('data.charges.2.amount', '0.00')
            ->assertJsonPath('data.charges.2.decision', 'waive')
            ->assertJsonPath('data.charges.2.reason', 'Called ahead, loyal client')
            ->assertJsonPath('data.charges.3.label', 'Fuel: 2 quarters missing');
    }

    public function test_new_damage_needs_a_decision_and_goes_on_the_car(): void
    {
        $this->backAt('2026-11-13 10:00');
        $mark = ['x' => 0.3, 'y' => 0.93, 'area' => 'Rear bumper', 'type' => 'scratch', 'size' => 'small'];

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'new_damages' => [$mark]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_damages.0.charge' => 'Decide for this damage: charge an amount, or 0 for no charge.']);

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'new_damages' => [$mark + ['charge' => ['amount' => 300, 'note' => 'Agreed with the client']]]])
            ->assertOk()
            ->assertJsonPath('data.charges.1.kind', 'damage')
            ->assertJsonPath('data.charges.1.label', 'Damage: Scratch - Rear bumper')
            ->assertJsonPath('data.charges.1.amount', '300.00')
            ->assertJsonPath('data.damages.0.stage', 'return')
            ->assertJsonPath('data.damages.0.known', false);

        $damage = VehicleDamage::sole();
        $this->assertSame(DamageStage::Return, $damage->found_stage);
        $this->assertSame($this->rental->id, $damage->rental_id);
        $this->assertTrue($damage->isOpen());
    }

    public function test_other_charges_can_be_added(): void
    {
        $this->backAt('2026-11-13 10:00');

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'extra_charges' => [['label' => 'Interior cleaning', 'amount' => 100]]])
            ->assertOk()
            ->assertJsonPath('data.charges.1.kind', 'extra')
            ->assertJsonPath('data.charges.1.label', 'Interior cleaning')
            ->assertJsonPath('data.total', '910.00');
    }

    // ---- Settling the money ----

    public function test_part_of_the_deposit_is_kept_and_the_rest_paid(): void
    {
        $this->backAt('2026-11-13 10:00');

        $this->giveBack([
            'mileage' => 61900,
            'fuel' => 4,
            'deposit_kept' => 500,
            'payment' => ['amount' => 310, 'method' => 'cash'],
        ])
            ->assertOk()
            ->assertJsonPath('data.deposit.kept', '500.00')
            ->assertJsonPath('data.payments.0.method', 'deposit')
            ->assertJsonPath('data.payments.0.amount', '500.00')
            ->assertJsonPath('data.payments.1.method', 'cash')
            ->assertJsonPath('data.paid', '810.00')
            ->assertJsonPath('data.balance', '0.00');
    }

    public function test_the_deposit_kept_and_the_payment_cannot_exceed_what_is_due(): void
    {
        $this->backAt('2026-11-13 10:00');
        $base = ['mileage' => 61900, 'fuel' => 4];

        $this->giveBack($base + ['deposit_kept' => 3500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deposit_kept' => "You can't keep more than the deposit (3000.00 MAD)."]);

        $this->giveBack($base + ['deposit_kept' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deposit_kept' => 'Keep only what is due (810.00 MAD).']);

        $this->giveBack($base + ['deposit_kept' => 800, 'payment' => ['amount' => 20, 'method' => 'cash']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment.amount' => "That's more than what is due (10.00 MAD)."]);
    }

    public function test_the_car_stays_reserved_when_the_next_booking_is_waiting(): void
    {
        $this->postJson('/api/rentals', [
            'vehicle_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'pickup_at' => '2026-11-20 10:00',
            'return_at' => '2026-11-22 10:00',
            'deposit_method' => 'cash',
        ])->assertCreated();
        $this->backAt('2026-11-13 10:00');

        $this->giveBack(['mileage' => 61900, 'fuel' => 4])->assertOk();

        $this->assertSame(VehicleStatus::Reserved, $this->car->fresh()->status);
    }

    // ---- Checks ----

    public function test_km_time_and_status_are_checked(): void
    {
        $this->backAt('2026-11-13 10:00');

        $this->giveBack(['mileage' => 61000, 'fuel' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mileage' => "The km can't be lower than at hand-over (61250)."]);

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'at' => '2026-11-14 10:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['at' => 'The return time cannot be in the future.']);

        $this->giveBack(['mileage' => 61900, 'fuel' => 4, 'at' => '2026-11-10 09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['at' => 'The return cannot be before the hand-over.']);

        $this->giveBack(['mileage' => 61900, 'fuel' => 4])->assertOk();
        $this->giveBack(['mileage' => 61900, 'fuel' => 4])->assertConflict();
    }

    // ---- Price suggestions (off by default, for later) ----

    public function test_an_agency_can_turn_on_price_suggestions(): void
    {
        $this->staff->agency->update(['suggest_return_prices' => true]);
        $this->backAt('2026-11-13 13:15');

        $this->getJson("/api/rentals/{$this->rental->id}/return?at=2026-11-13%2013:15&mileage=62120&fuel=2")
            ->assertJsonPath('data.facts.suggested.extra_km', '180.00')  // 120 km x 1.50
            ->assertJsonPath('data.facts.suggested.late', '270.00')      // 1 day x 270
            ->assertJsonPath('data.facts.suggested.fuel', '300.00');     // 2 x 150

        $decisions = ['extra_km' => ['amount' => 180], 'late' => ['amount' => 100], 'fuel' => ['amount' => 300]];

        $this->giveBack(['mileage' => 62120, 'fuel' => 2, 'charges' => $decisions])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['charges.late.note' => 'Give a reason for charging less than suggested.']);

        $decisions['late']['note'] = 'Traffic jam';
        $this->giveBack(['mileage' => 62120, 'fuel' => 2, 'charges' => $decisions])
            ->assertOk()
            ->assertJsonPath('data.charges.2.suggested', '270.00')
            ->assertJsonPath('data.charges.2.decision', 'reduce');
    }

    public function test_another_agency_rental_cannot_be_returned(): void
    {
        $this->app['auth']->forgetGuards();
        $other = Rental::factory()->create();
        $this->actingAs($this->staff);

        $this->getJson("/api/rentals/{$other->id}/return")->assertNotFound();
        $this->postJson("/api/rentals/{$other->id}/return", ['mileage' => 1, 'fuel' => 4])->assertNotFound();
    }
}
