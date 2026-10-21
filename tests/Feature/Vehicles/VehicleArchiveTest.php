<?php

namespace Tests\Feature\Vehicles;

use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    private function car(VehicleStatus $status = VehicleStatus::Available): Vehicle
    {
        return Vehicle::factory()->for($this->owner->agency)->status($status)->create();
    }

    public function test_the_owner_can_archive_a_sold_car_with_a_reason(): void
    {
        $car = $this->car();

        $this->actingAs($this->owner)
            ->postJson("/api/vehicles/{$car->id}/archive", ['reason' => 'Sold to a garage'])
            ->assertOk()
            ->assertJsonPath('data.archive_reason', 'Sold to a garage')
            ->assertJsonPath('data.status', 'out-of-service');

        // Archived, not deleted: the row (and later its history) stays.
        $this->assertNotNull($car->fresh()->archived_at);
    }

    public function test_a_reason_is_required(): void
    {
        $car = $this->car();

        $this->actingAs($this->owner)
            ->postJson("/api/vehicles/{$car->id}/archive", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_staff_cannot_archive_a_car(): void
    {
        $car = $this->car();
        $staff = User::factory()->for($this->owner->agency)->create();

        $this->actingAs($staff)
            ->postJson("/api/vehicles/{$car->id}/archive", ['reason' => 'Sold'])
            ->assertForbidden();
    }

    public function test_a_rented_or_reserved_car_cannot_be_archived(): void
    {
        foreach ([VehicleStatus::Rented, VehicleStatus::Reserved] as $status) {
            $car = $this->car($status);

            $this->actingAs($this->owner)
                ->postJson("/api/vehicles/{$car->id}/archive", ['reason' => 'Sold'])
                ->assertConflict();

            $this->assertNull($car->fresh()->archived_at);
        }
    }

    public function test_the_owner_can_restore_an_archived_car(): void
    {
        $car = Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->owner)
            ->deleteJson("/api/vehicles/{$car->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.status', 'available');
    }

    public function test_cars_cannot_be_deleted(): void
    {
        $car = $this->car();

        $this->actingAs($this->owner)
            ->deleteJson("/api/vehicles/{$car->id}")
            ->assertMethodNotAllowed();

        $this->assertModelExists($car);
    }
}
