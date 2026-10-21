<?php

namespace Tests\Feature\Vehicles;

use App\Enums\VehicleStatus;
use App\Models\Agency;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    /**
     * A valid "add car" form, like the Vue form would send it.
     *
     * @return array<string, mixed>
     */
    private function carForm(array $overrides = []): array
    {
        return array_merge([
            'make' => 'Dacia',
            'model' => 'Logan',
            'year' => 2023,
            'plate' => '30577-B-6',
            'vin' => 'VF1LSRAB123456789',
            'category' => 'economy',
            'fuel' => 'diesel',
            'transmission' => 'manual',
            'seats' => 5,
            'doors' => 4,
            'color' => 'Grey',
            'mileage' => 61200,
            'first_registration' => '2023-03-15',
            'notes' => null,
            'daily_price' => 270,
            'weekly_price' => 1620,
            'monthly_price' => 5940,
            'deposit' => 3000,
            'included_km_per_day' => 250,
            'extra_km_fee' => 1.5,
        ], $overrides);
    }

    private function carOf(User $user, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->for($user->agency)->create($attributes);
    }

    // ---- Adding cars ----

    public function test_the_owner_can_add_a_car(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm())
            ->assertCreated()
            ->assertJsonPath('data.fleet_code', 'V-001')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.pricing.daily', '270.00')
            ->assertJsonPath('data.pricing.extra_km_fee', '1.50');

        $this->assertDatabaseHas('vehicles', [
            'agency_id' => $this->owner->agency_id,
            'plate' => '30577-B-6',
        ]);
    }

    public function test_staff_cannot_add_a_car(): void
    {
        $staff = User::factory()->for($this->owner->agency)->create();

        $this->actingAs($staff)
            ->postJson('/api/vehicles', $this->carForm())
            ->assertForbidden();
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
        $this->postJson('/api/vehicles', $this->carForm())->assertUnauthorized();
    }

    public function test_fleet_codes_are_numbered_per_agency(): void
    {
        $otherOwner = User::factory()->owner()->create();
        $this->carOf($otherOwner);
        $this->carOf($otherOwner);

        $this->actingAs($this->owner);
        $first = $this->postJson('/api/vehicles', $this->carForm(['plate' => '11111-A-1', 'vin' => null]));
        $second = $this->postJson('/api/vehicles', $this->carForm(['plate' => '22222-A-1', 'vin' => null]));

        $first->assertJsonPath('data.fleet_code', 'V-001');
        $second->assertJsonPath('data.fleet_code', 'V-002');
    }

    public function test_fleet_codes_of_archived_cars_are_never_reused(): void
    {
        Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm())
            ->assertJsonPath('data.fleet_code', 'V-002');
    }

    public function test_the_plate_is_saved_uppercase_without_spaces(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['plate' => ' 30577 - b - 6 ']))
            ->assertCreated()
            ->assertJsonPath('data.plate', '30577-B-6');
    }

    public function test_two_cars_of_the_same_agency_cannot_share_a_plate(): void
    {
        $this->carOf($this->owner, ['plate' => '30577-B-6']);

        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['plate' => '30577 b 6']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plate' => 'Another vehicle already uses this plate number.']);
    }

    public function test_plates_with_arabic_letters_are_compared_the_same_way(): void
    {
        $this->carOf($this->owner, ['plate' => '12345-ب-6']);

        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['plate' => '12345 ب 6']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plate');

        $this->postJson('/api/vehicles', $this->carForm(['plate' => '12345-أ-6']))
            ->assertCreated();
    }

    public function test_a_car_keeps_its_own_plate_when_edited_but_cannot_take_another(): void
    {
        $car = $this->carOf($this->owner, ['plate' => '30577-B-6']);
        $this->carOf($this->owner, ['plate' => '27318-D-1']);

        $this->actingAs($this->owner)
            ->putJson("/api/vehicles/{$car->id}", $this->carForm(['plate' => '30577 b 6']))
            ->assertOk();

        $this->putJson("/api/vehicles/{$car->id}", $this->carForm(['plate' => '27318-D-1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plate');
    }

    public function test_another_agency_may_have_a_car_with_the_same_plate(): void
    {
        $this->carOf(User::factory()->create(), ['plate' => '30577-B-6']);

        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['plate' => '30577-B-6']))
            ->assertCreated();
    }

    public function test_the_vin_must_be_17_characters_without_i_o_q(): void
    {
        $this->actingAs($this->owner);

        foreach (['TOO-SHORT', 'VF1LSRAB12345678O', 'VF1LSRAB1234567890'] as $vin) {
            $this->postJson('/api/vehicles', $this->carForm(['vin' => $vin]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('vin');
        }

        $this->postJson('/api/vehicles', $this->carForm(['vin' => 'vf1lsrab123456789']))
            ->assertCreated()
            ->assertJsonPath('data.vin', 'VF1LSRAB123456789');
    }

    public function test_prices_are_whole_dirhams_but_the_km_fee_may_have_cents(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['daily_price' => 270.5, 'deposit' => '3000.50']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['daily_price', 'deposit']);

        $this->postJson('/api/vehicles', $this->carForm(['extra_km_fee' => 1.255]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extra_km_fee');

        $this->postJson('/api/vehicles', $this->carForm(['extra_km_fee' => 2.5]))
            ->assertCreated()
            ->assertJsonPath('data.pricing.extra_km_fee', '2.50');
    }

    public function test_required_fields_are_checked(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'make', 'model', 'year', 'plate', 'category', 'fuel', 'transmission',
                'seats', 'doors', 'mileage', 'daily_price', 'weekly_price', 'monthly_price',
                'deposit', 'extra_km_fee',
            ]);
    }

    public function test_status_and_agency_cannot_be_sent_in_the_form(): void
    {
        $otherAgency = Agency::factory()->create();

        $this->actingAs($this->owner)
            ->postJson('/api/vehicles', $this->carForm(['status' => 'rented', 'agency_id' => $otherAgency->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'available');

        $this->assertDatabaseHas('vehicles', ['plate' => '30577-B-6', 'agency_id' => $this->owner->agency_id]);
    }

    // ---- Editing cars ----

    public function test_the_owner_can_edit_a_car_and_keep_its_own_plate(): void
    {
        $car = $this->carOf($this->owner, ['plate' => '30577-B-6']);

        $this->actingAs($this->owner)
            ->putJson("/api/vehicles/{$car->id}", $this->carForm(['plate' => '30577-B-6', 'mileage' => 70000]))
            ->assertOk()
            ->assertJsonPath('data.mileage', 70000)
            ->assertJsonPath('data.fleet_code', 'V-001');
    }

    public function test_staff_cannot_edit_a_car(): void
    {
        $car = $this->carOf($this->owner);
        $staff = User::factory()->for($this->owner->agency)->create();

        $this->actingAs($staff)
            ->putJson("/api/vehicles/{$car->id}", $this->carForm())
            ->assertForbidden();
    }

    public function test_an_archived_car_cannot_be_edited(): void
    {
        $car = Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->owner)
            ->putJson("/api/vehicles/{$car->id}", $this->carForm())
            ->assertConflict();
    }

    // ---- Viewing cars ----

    public function test_staff_can_see_the_fleet(): void
    {
        $car = $this->carOf($this->owner);
        $staff = User::factory()->for($this->owner->agency)->create();

        $this->actingAs($staff)->getJson('/api/vehicles')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson("/api/vehicles/{$car->id}")->assertOk();
    }

    public function test_the_list_only_shows_the_own_agency_cars(): void
    {
        $this->carOf($this->owner, ['model' => 'Logan']);
        $this->carOf(User::factory()->create(), ['model' => 'Duster']);

        $this->actingAs($this->owner)
            ->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model', 'Logan')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_another_agency_car_is_not_found(): void
    {
        $carOfOtherAgency = $this->carOf(User::factory()->create());

        $this->actingAs($this->owner)
            ->getJson("/api/vehicles/{$carOfOtherAgency->id}")
            ->assertNotFound();

        $this->putJson("/api/vehicles/{$carOfOtherAgency->id}", $this->carForm())
            ->assertNotFound();
    }

    public function test_archived_cars_are_hidden_unless_asked_for(): void
    {
        $this->carOf($this->owner, ['model' => 'Logan']);
        Vehicle::factory()->for($this->owner->agency)->archived()->create(['model' => 'Old Duster']);

        $this->actingAs($this->owner);

        $this->getJson('/api/vehicles')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model', 'Logan');

        $this->getJson('/api/vehicles?archived=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model', 'Old Duster');
    }

    public function test_the_list_can_be_filtered_by_status_and_category(): void
    {
        $this->carOf($this->owner, ['model' => 'Logan', 'category' => 'economy']);
        $this->carOf($this->owner, ['model' => 'Duster', 'category' => 'suv', 'status' => VehicleStatus::Maintenance]);

        $this->actingAs($this->owner);

        $this->getJson('/api/vehicles?status=maintenance')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.model', 'Duster');

        $this->getJson('/api/vehicles?category=economy')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.model', 'Logan');

        $this->getJson('/api/vehicles?status=flying')->assertUnprocessable();
    }

    public function test_the_list_can_be_searched_by_fleet_code_plate_or_name(): void
    {
        $this->carOf($this->owner, ['make' => 'Dacia', 'model' => 'Logan', 'plate' => '30577-B-6']);
        $this->carOf($this->owner, ['make' => 'Kia', 'model' => 'Picanto', 'plate' => '27318-D-1']);

        $this->actingAs($this->owner);

        foreach (['V-002', 'v2', '27318', 'picanto', 'Kia Pic'] as $search) {
            $this->getJson('/api/vehicles?search='.urlencode($search))
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.model', 'Picanto');
        }
    }

    public function test_the_list_is_sorted_by_fleet_code(): void
    {
        $this->carOf($this->owner, ['model' => 'First']);
        $this->carOf($this->owner, ['model' => 'Second']);

        $this->actingAs($this->owner)
            ->getJson('/api/vehicles')
            ->assertJsonPath('data.0.fleet_code', 'V-001')
            ->assertJsonPath('data.1.fleet_code', 'V-002');
    }
}
