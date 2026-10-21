<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\DriverCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriverCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(); // agency: min age 21, licence 2 years
    }

    private function customer(string $birth, ?string $licence): Customer
    {
        return Customer::factory()->for($this->staff->agency)->create([
            'birth_date' => $birth,
            'licence_number' => $licence ? 'XX-LIC-1' : null,
            'licence_issue_date' => $licence,
        ]);
    }

    private function check(Customer $customer, string $on): TestResponse
    {
        return $this->actingAs($this->staff)->getJson("/api/customers/{$customer->id}/driver-check?on={$on}");
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function birthdays(): array
    {
        return [
            'day before birthday' => ['2000-11-15', '2026-11-14', 25],
            'on birthday' => ['2000-11-15', '2026-11-15', 26],
            'born on 29 February, 28 Feb in a normal year' => ['2004-02-29', '2025-02-28', 20],
            'born on 29 February, 1 March' => ['2004-02-29', '2025-03-01', 21],
        ];
    }

    #[DataProvider('birthdays')]
    public function test_full_years_are_counted_like_birthdays(string $from, string $to, int $years): void
    {
        $this->assertSame($years, DriverCheck::fullYearsBetween($from, $to));
    }

    public function test_an_adult_with_an_old_licence_can_drive(): void
    {
        $this->check($this->customer('1990-04-12', '2010-06-01'), '2026-11-15')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.on', '2026-11-15')
            ->assertJsonPath('data.checks.0.key', 'age')
            ->assertJsonPath('data.checks.0.value', 36)
            ->assertJsonPath('data.checks.0.message', '36 years old (minimum 21)')
            ->assertJsonPath('data.checks.1.message', 'Licence held for 16 years (minimum 2)');
    }

    public function test_the_driver_must_be_21_on_the_pickup_date(): void
    {
        $customer = $this->customer('2005-11-15', '2023-01-10');

        $this->check($customer, '2026-11-14')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.0.ok', false)
            ->assertJsonPath('data.checks.0.value', 20);

        $this->check($customer, '2026-11-15')
            ->assertJsonPath('data.eligible', true);
    }

    public function test_the_licence_must_be_held_for_2_years_on_the_pickup_date(): void
    {
        $customer = $this->customer('1995-01-01', '2024-11-15');

        $this->check($customer, '2026-11-14')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.1.key', 'licence')
            ->assertJsonPath('data.checks.1.message', 'Licence held for 1 year (minimum 2)');

        $this->check($customer, '2026-11-15')
            ->assertJsonPath('data.eligible', true);
    }

    public function test_no_licence_means_cannot_drive(): void
    {
        $this->check($this->customer('1990-04-12', null), '2026-11-15')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.1.message', 'No driving licence recorded');
    }

    public function test_the_rules_come_from_the_agency_settings(): void
    {
        $this->staff->agency->update(['driver_min_age' => 25, 'licence_min_years' => 3]);
        $customer = $this->customer('2003-01-01', '2022-01-01'); // 23 years old, licence 4 years

        $this->check($customer, '2026-11-15')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.0.message', '23 years old (minimum 25)')
            ->assertJsonPath('data.checks.1.ok', true);
    }

    public function test_a_blocked_customer_cannot_rent_and_staff_see_why(): void
    {
        $customer = Customer::factory()->for($this->staff->agency)->blocked('Unpaid damage')->create();

        $this->check($customer, '2026-11-15')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.2.key', 'blocked')
            ->assertJsonPath('data.checks.2.message', 'Blocked: Unpaid damage');
    }

    public function test_an_archived_customer_cannot_rent(): void
    {
        $customer = Customer::factory()->for($this->staff->agency)->archived()->create();

        $this->check($customer, '2026-11-15')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.checks.2.key', 'archived');
    }

    public function test_without_a_date_today_in_the_agency_timezone_is_used(): void
    {
        // 00:30 on Nov 15 in Casablanca = still Nov 14 in UTC
        $this->travelTo(now()->parse('2026-11-15 00:30:00', 'Africa/Casablanca'));
        $customer = $this->customer('2005-11-15', '2023-01-10'); // turns 21 on Nov 15

        $this->actingAs($this->staff)
            ->getJson("/api/customers/{$customer->id}/driver-check")
            ->assertJsonPath('data.on', '2026-11-15')
            ->assertJsonPath('data.eligible', true);
    }

    public function test_the_date_must_be_a_real_date(): void
    {
        $this->check($this->customer('1990-04-12', '2010-06-01'), '15/11/2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('on');
    }

    public function test_another_agency_customer_is_not_found(): void
    {
        $other = Customer::factory()->create();

        $this->check($other, '2026-11-15')->assertNotFound();
    }
}
