<?php

namespace Tests\Feature\Maintenance;

use App\Enums\DocumentType;
use App\Enums\MaintenanceStatus;
use App\Models\Customer;
use App\Models\MaintenanceJob;
use App\Models\Rental;
use App\Models\RentalCharge;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-10-10 10:00:00', 'Africa/Casablanca'));
        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create();
        $this->car = Vehicle::factory()->for($this->owner->agency)->create(['make' => 'Dacia', 'model' => 'Logan', 'mileage' => 61200]);
        foreach (DocumentType::cases() as $type) {
            VehicleDocument::factory()->for($this->car)->create([
                'type' => $type, 'issue_date' => '2026-01-01', 'expiry_date' => $type->hasExpiry() ? '2027-06-30' : null,
            ]);
        }
    }

    /**
     * Real flow: book → hand over → return with a new scratch charged $charged MAD.
     */
    private function rentalWithNewScratch(int $charged): VehicleDamage
    {
        $customer = Customer::factory()->for($this->owner->agency)->create(['first_name' => 'Amine', 'last_name' => 'Kettani']);
        $this->actingAs($this->staff)->postJson('/api/rentals', [
            'vehicle_id' => $this->car->id, 'customer_id' => $customer->id,
            'pickup_at' => '2026-10-10 10:00', 'return_at' => '2026-10-12 10:00', 'deposit_method' => 'cash',
        ])->assertCreated();
        $rental = Rental::latest('id')->first();
        $this->postJson("/api/rentals/{$rental->id}/handover", ['mileage' => 61250, 'fuel' => 4])->assertOk();

        $this->travelTo(now()->parse('2026-10-12 10:00:00', 'Africa/Casablanca'));
        $this->postJson("/api/rentals/{$rental->id}/return", [
            'mileage' => 61500, 'fuel' => 4,
            'new_damages' => [['x' => 0.3, 'y' => 0.93, 'area' => 'Rear bumper', 'type' => 'scratch', 'size' => 'small', 'charge' => ['amount' => $charged]]],
        ])->assertOk();

        return VehicleDamage::latest('id')->first();
    }

    // ---- The link between a damage and its charge ----

    public function test_the_damage_charge_at_return_is_linked_to_the_damage(): void
    {
        $damage = $this->rentalWithNewScratch(300);

        $charge = RentalCharge::where('kind', 'damage')->sole();
        $this->assertSame($damage->id, $charge->vehicle_damage_id);
        $this->assertSame('300.00', $damage->charges->sole()->amount);
    }

    // ---- Damage report: charged vs repair cost ----

    public function test_the_damage_report_compares_charged_and_repair_cost(): void
    {
        $damage = $this->rentalWithNewScratch(300);
        $this->travelTo(now()->parse('2026-10-15 10:00:00', 'Africa/Casablanca'));
        $this->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-14', 'repair_cost' => 400])->assertOk();

        $this->actingAs($this->owner)
            ->getJson('/api/reports/damages?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.damages.0.vehicle.fleet_code', 'V-001')
            ->assertJsonPath('data.damages.0.rental_code', 'R-0001')
            ->assertJsonPath('data.damages.0.customer', 'Amine Kettani')
            ->assertJsonPath('data.damages.0.charged', '300.00')
            ->assertJsonPath('data.damages.0.repair_cost', '400.00')
            ->assertJsonPath('data.damages.0.difference', '-100.00')
            ->assertJsonPath('data.totals', [
                'damages' => 1, 'not_repaired_yet' => 0, 'charged' => '300.00', 'repair_cost' => '400.00', 'difference' => '-100.00',
            ]);
    }

    public function test_a_forgiven_and_not_yet_repaired_damage(): void
    {
        $this->rentalWithNewScratch(0); // no charge: loyal client

        $this->actingAs($this->owner)
            ->getJson('/api/reports/damages?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('data.damages.0.charged', '0.00')
            ->assertJsonPath('data.damages.0.decision', 'waive')
            ->assertJsonPath('data.damages.0.repair_cost', null)
            ->assertJsonPath('data.damages.0.difference', null)
            ->assertJsonPath('data.totals.not_repaired_yet', 1)
            ->assertJsonPath('data.totals.difference', '0.00');
    }

    public function test_damage_found_outside_a_rental_is_not_in_the_report(): void
    {
        VehicleDamage::factory()->for($this->car)->create(['found_at' => now()]);

        $this->actingAs($this->owner)->getJson('/api/reports/damages')->assertJsonPath('data.totals.damages', 0);
    }

    // ---- Maintenance costs ----

    public function test_costs_per_car_and_per_month(): void
    {
        $other = Vehicle::factory()->for($this->owner->agency)->create(['make' => 'Kia', 'model' => 'Picanto']);
        $done = fn (Vehicle $car, string $local, int $cost) => MaintenanceJob::factory()->for($car)->create([
            'agency_id' => $this->owner->agency_id, 'status' => MaintenanceStatus::Done,
            'started_at' => now()->parse($local, 'Africa/Casablanca'), 'completed_at' => now()->parse($local, 'Africa/Casablanca'), 'cost' => $cost,
        ]);
        $done($this->car, '2026-09-20 11:00', 450);
        $done($this->car, '2026-10-05 11:00', 1200);
        $done($other, '2026-10-06 11:00', 300);
        $done($other, '2026-08-01 11:00', 999); // outside the period
        MaintenanceJob::factory()->for($this->car)->create(['agency_id' => $this->owner->agency_id, 'cost' => 5000]); // not done
        VehicleDamage::factory()->for($this->car)->repaired(600)->create(['repaired_at' => '2026-10-08']);

        $this->actingAs($this->owner)
            ->getJson('/api/reports/maintenance-costs?from=2026-09-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.totals', ['maintenance' => '1950.00', 'damage_repairs' => '600.00', 'total' => '2550.00'])
            ->assertJsonPath('data.by_vehicle.0.vehicle.fleet_code', 'V-001')
            ->assertJsonPath('data.by_vehicle.0.total', '2250.00')
            ->assertJsonPath('data.by_vehicle.1.total', '300.00')
            ->assertJsonPath('data.by_month', [
                ['month' => '2026-09', 'maintenance' => '450.00', 'damage_repairs' => '0.00', 'total' => '450.00'],
                ['month' => '2026-10', 'maintenance' => '1500.00', 'damage_repairs' => '600.00', 'total' => '2100.00'],
            ]);
    }

    public function test_the_default_period_is_the_last_30_days(): void
    {
        $this->actingAs($this->owner)
            ->getJson('/api/reports/maintenance-costs')
            ->assertJsonPath('data.period', ['from' => '2026-09-11', 'to' => '2026-10-10']);
    }

    // ---- Who sees money ----

    public function test_reports_are_for_the_owner_only_and_their_agency_only(): void
    {
        MaintenanceJob::factory()->create(['status' => MaintenanceStatus::Done, 'completed_at' => now(), 'cost' => 9999]); // another agency

        $this->actingAs($this->staff)->getJson('/api/reports/maintenance-costs')->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/reports/damages')->assertForbidden();

        $this->actingAs($this->owner)
            ->getJson('/api/reports/maintenance-costs')
            ->assertJsonPath('data.totals.total', '0.00');
    }
}
