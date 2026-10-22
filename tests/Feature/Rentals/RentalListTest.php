<?php

namespace Tests\Feature\Rentals;

use App\Enums\RentalStatus;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lists, filters and the day's overview (dashboard).
 * "Today" = Thursday 22 Oct 2026, 12:00 at the agency.
 */
class RentalListTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-10-22 12:00:00', 'Africa/Casablanca'));
        $this->staff = User::factory()->create();
    }

    /**
     * A rental at agency-local times (created with nobody logged in).
     */
    private function rental(RentalStatus $status, string $pickup, string $return, array $attributes = []): Rental
    {
        $local = fn (string $time) => now()->parse($time, 'Africa/Casablanca');

        return Rental::factory()->for($this->staff->agency)->status($status)->create([
            'pickup_at' => $local($pickup),
            'return_at' => $local($return),
            ...array_map(fn ($value) => is_string($value) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $value) ? $local($value) : $value, $attributes),
        ]);
    }

    private function codes(string $url): array
    {
        return collect($this->actingAs($this->staff)->getJson($url)->assertOk()->json('data'))->pluck('rental_code')->all();
    }

    // ---- Filters ----

    public function test_status_filter_with_overdue_computed(): void
    {
        $reserved = $this->rental(RentalStatus::Reserved, '2026-10-25 10:00', '2026-10-27 10:00');
        $onTime = $this->rental(RentalStatus::Active, '2026-10-20 10:00', '2026-10-24 10:00');
        $late = $this->rental(RentalStatus::Active, '2026-10-18 10:00', '2026-10-22 10:30'); // 1 h 30 ago
        $withinGrace = $this->rental(RentalStatus::Active, '2026-10-18 10:00', '2026-10-22 11:30'); // 30 min ago

        $this->assertSame([$reserved->rental_code], $this->codes('/api/rentals?status=reserved'));
        $this->assertEqualsCanonicalizing(
            [$onTime->rental_code, $late->rental_code, $withinGrace->rental_code],
            $this->codes('/api/rentals?status=active'),
        );
        $this->assertSame([$late->rental_code], $this->codes('/api/rentals?status=overdue'));

        $this->getJson('/api/rentals?status=lost')->assertUnprocessable();
    }

    public function test_an_overdue_rental_shows_as_overdue_with_minutes_late(): void
    {
        $late = $this->rental(RentalStatus::Active, '2026-10-18 10:00', '2026-10-22 10:30');

        $this->actingAs($this->staff)
            ->getJson("/api/rentals/{$late->id}")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.display_status', 'overdue')
            ->assertJsonPath('data.minutes_late', 90);
    }

    public function test_search_by_rental_code_customer_phone_or_car(): void
    {
        $car = Vehicle::factory()->for($this->staff->agency)->create(['make' => 'Kia', 'model' => 'Picanto', 'plate' => '27318-D-1']);
        $customer = Customer::factory()->for($this->staff->agency)->create(['first_name' => 'Nadia', 'last_name' => 'El Idrissi', 'phone' => '+212611223344']);
        $this->rental(RentalStatus::Reserved, '2026-10-25 10:00', '2026-10-27 10:00'); // R-0001, someone else
        $wanted = $this->rental(RentalStatus::Reserved, '2026-10-26 10:00', '2026-10-28 10:00', [
            'vehicle_id' => $car->id, 'customer_id' => $customer->id, 'driver_id' => $customer->id,
        ]);

        foreach (['R-0002', 'r2', 'Nadia', 'el idrissi', '0611 22 33', 'picanto', 'Kia Pic', '27318', 'V-001'] as $search) {
            $this->assertSame([$wanted->rental_code], $this->codes('/api/rentals?search='.urlencode($search)), "search: {$search}");
        }
    }

    public function test_filter_by_car_customer_and_period(): void
    {
        $first = $this->rental(RentalStatus::Completed, '2026-10-01 10:00', '2026-10-04 10:00');
        $second = $this->rental(RentalStatus::Reserved, '2026-11-01 10:00', '2026-11-03 10:00');

        $this->assertSame([$first->rental_code], $this->codes("/api/rentals?vehicle_id={$first->vehicle_id}"));
        $this->assertSame([$second->rental_code], $this->codes("/api/rentals?customer_id={$second->customer_id}"));
        // Rentals overlapping 3–5 October
        $this->assertSame([$first->rental_code], $this->codes('/api/rentals?from=2026-10-03&to=2026-10-05'));
        $this->assertSame([], $this->codes('/api/rentals?from=2026-10-10&to=2026-10-20'));
    }

    public function test_a_driver_also_finds_their_rentals(): void
    {
        $driver = Customer::factory()->for($this->staff->agency)->create();
        $rental = $this->rental(RentalStatus::Reserved, '2026-10-25 10:00', '2026-10-27 10:00', ['driver_id' => $driver->id]);

        $this->assertSame([$rental->rental_code], $this->codes("/api/rentals?customer_id={$driver->id}"));
    }

    // ---- The day's overview ----

    public function test_the_overview_shows_today_overdue_and_upcoming(): void
    {
        $pickupLater = $this->rental(RentalStatus::Reserved, '2026-10-22 17:00', '2026-10-25 17:00');
        $pickedUp = $this->rental(RentalStatus::Active, '2026-10-22 09:00', '2026-10-29 09:00');
        $dueTonight = $this->rental(RentalStatus::Active, '2026-10-20 18:00', '2026-10-22 18:00');
        $returnedThisMorning = $this->rental(RentalStatus::Completed, '2026-10-19 10:00', '2026-10-22 10:00', ['returned_at' => '2026-10-22 10:15']);
        $overdue = $this->rental(RentalStatus::Active, '2026-10-16 10:00', '2026-10-21 10:00');
        $inTwoDays = $this->rental(RentalStatus::Reserved, '2026-10-24 10:00', '2026-10-28 10:00');
        $this->rental(RentalStatus::Reserved, '2026-11-15 10:00', '2026-11-18 10:00'); // too far
        $this->rental(RentalStatus::Cancelled, '2026-10-22 11:00', '2026-10-23 11:00'); // cancelled

        $data = $this->actingAs($this->staff)->getJson('/api/rentals/overview')->assertOk()->json('data');
        $codes = fn (string $key) => collect($data[$key])->pluck('rental_code')->all();

        $this->assertSame('2026-10-22', $data['date']);
        $this->assertSame([$pickedUp->rental_code, $pickupLater->rental_code], $codes('pickups_today'));
        $this->assertSame([$returnedThisMorning->rental_code, $dueTonight->rental_code], $codes('returns_today'));
        $this->assertSame([$overdue->rental_code], $codes('overdue'));
        $this->assertSame([$pickupLater->rental_code, $inTwoDays->rental_code], $codes('upcoming'));
        $this->assertSame(['pickups_today' => 2, 'returns_today' => 2, 'overdue' => 1, 'upcoming' => 2, 'active' => 3, 'reserved' => 3], $data['counts']);
    }

    public function test_the_overview_uses_the_agency_day(): void
    {
        // An agency in a timezone ahead of UTC (Africa/Lagos = UTC+1 all year): at 00:30 there it's already the 23rd
        $this->staff->agency->update(['timezone' => 'Africa/Lagos']);
        $this->travelTo(now()->parse('2026-10-23 00:30:00', 'Africa/Lagos'));
        $rental = Rental::factory()->for($this->staff->agency)->create([
            'pickup_at' => now()->parse('2026-10-23 09:00', 'Africa/Lagos'),
            'return_at' => now()->parse('2026-10-25 09:00', 'Africa/Lagos'),
        ]);

        $this->actingAs($this->staff)
            ->getJson('/api/rentals/overview')
            ->assertJsonPath('data.date', '2026-10-23')
            ->assertJsonPath('data.pickups_today.0.rental_code', $rental->rental_code);
    }

    public function test_the_overview_only_shows_the_own_agency(): void
    {
        Rental::factory()->create(['pickup_at' => now()->addHours(2), 'return_at' => now()->addDays(2)]); // another agency

        $this->actingAs($this->staff)
            ->getJson('/api/rentals/overview')
            ->assertJsonPath('data.counts.pickups_today', 0)
            ->assertJsonPath('data.counts.reserved', 0);
    }
}
