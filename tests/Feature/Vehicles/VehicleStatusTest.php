<?php

namespace Tests\Feature\Vehicles;

use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create();
    }

    private function car(VehicleStatus $status = VehicleStatus::Available): Vehicle
    {
        return Vehicle::factory()->for($this->staff->agency)->status($status)->create();
    }

    public function test_staff_can_send_a_car_to_maintenance_and_back(): void
    {
        $car = $this->car();

        $this->actingAs($this->staff)
            ->patchJson("/api/vehicles/{$car->id}/status", ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance');

        $this->patchJson("/api/vehicles/{$car->id}/status", ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available');
    }

    public function test_rented_or_reserved_cannot_be_set_by_hand(): void
    {
        $car = $this->car();

        foreach (['rented', 'reserved'] as $status) {
            $this->actingAs($this->staff)
                ->patchJson("/api/vehicles/{$car->id}/status", ['status' => $status])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }
    }

    public function test_a_rented_car_status_follows_its_rental(): void
    {
        $car = $this->car(VehicleStatus::Rented);

        $this->actingAs($this->staff)
            ->patchJson("/api/vehicles/{$car->id}/status", ['status' => 'available'])
            ->assertConflict();

        $this->assertSame(VehicleStatus::Rented, $car->fresh()->status);
    }

    public function test_an_archived_car_status_cannot_change(): void
    {
        $car = Vehicle::factory()->for($this->staff->agency)->archived()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/vehicles/{$car->id}/status", ['status' => 'available'])
            ->assertConflict();
    }

    public function test_another_agency_car_status_cannot_change(): void
    {
        $carOfOtherAgency = Vehicle::factory()->create();

        $this->actingAs($this->staff)
            ->patchJson("/api/vehicles/{$carOfOtherAgency->id}/status", ['status' => 'maintenance'])
            ->assertNotFound();
    }
}
