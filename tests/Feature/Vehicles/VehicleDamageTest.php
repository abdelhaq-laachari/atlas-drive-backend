<?php

namespace Tests\Feature\Vehicles;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleDamageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-10-21 12:00:00', 'Africa/Casablanca'));
        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create();
        $this->car = Vehicle::factory()->for($this->owner->agency)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function damageForm(array $overrides = []): array
    {
        return array_merge([
            'x' => 0.3,
            'y' => 0.93,
            'area' => 'Rear bumper',
            'type' => 'scratch',
            'size' => 'small',
            'note' => 'About 5 cm',
        ], $overrides);
    }

    private function damage(array $attributes = [], ?Vehicle $car = null): VehicleDamage
    {
        return VehicleDamage::factory()->for($car ?? $this->car)->create($attributes);
    }

    // ---- Recording damage ----

    public function test_staff_can_record_damage_found_on_a_car(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/damages", $this->damageForm())
            ->assertCreated()
            ->assertJsonPath('data.area', 'Rear bumper')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.found_stage', null)
            ->assertJsonPath('data.x', 0.3);

        $this->assertNotNull(VehicleDamage::sole()->found_at);
    }

    public function test_the_position_must_be_on_the_drawing_and_the_part_known(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/damages", $this->damageForm([
                'x' => 1.5, 'y' => -0.1, 'area' => 'Spoiler', 'type' => 'melted', 'size' => 'huge',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['x', 'y', 'area', 'type', 'size']);
    }

    public function test_a_found_time_with_an_offset_is_saved_in_utc(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/damages", $this->damageForm(['found_at' => '2026-10-20T10:00:00+01:00']))
            ->assertCreated();

        $this->assertSame('2026-10-20 09:00:00', VehicleDamage::sole()->getRawOriginal('found_at'));
    }

    public function test_damage_cannot_be_found_in_the_future(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/damages", $this->damageForm(['found_at' => now()->addDay()->toIso8601String()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('found_at');
    }

    public function test_no_damage_can_be_recorded_on_an_archived_car(): void
    {
        $archived = Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->owner)
            ->postJson("/api/vehicles/{$archived->id}/damages", $this->damageForm())
            ->assertConflict();
    }

    public function test_the_car_list_shows_how_many_open_damages_each_car_has(): void
    {
        $this->damage();
        $this->damage();
        $this->damage()->forceFill(['status' => 'repaired', 'repaired_at' => '2026-10-20'])->save();

        $this->actingAs($this->staff);

        $this->getJson('/api/vehicles')->assertJsonPath('data.0.open_damages_count', 2);
        $this->getJson("/api/vehicles/{$this->car->id}")->assertJsonPath('data.open_damages_count', 2);
    }

    // ---- Listing ----

    public function test_damage_is_listed_oldest_first_and_can_be_filtered_by_status(): void
    {
        $newer = $this->damage(['found_at' => now()->subDays(2), 'area' => 'Bonnet']);
        $older = $this->damage(['found_at' => now()->subDays(20), 'area' => 'Roof']);
        VehicleDamage::factory()->for($this->car)->repaired()->create(['found_at' => now()->subDays(40)]);

        $this->actingAs($this->staff);

        $this->getJson("/api/vehicles/{$this->car->id}/damages")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson("/api/vehicles/{$this->car->id}/damages?status=open")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id);
    }

    public function test_another_agency_car_or_damage_is_not_found(): void
    {
        $otherCar = Vehicle::factory()->create();
        $otherDamage = $this->damage([], $otherCar);

        $this->actingAs($this->owner);

        $this->getJson("/api/vehicles/{$otherCar->id}/damages")->assertNotFound();
        $this->postJson("/api/vehicles/{$otherCar->id}/damages", $this->damageForm())->assertNotFound();
        $this->putJson("/api/vehicle-damages/{$otherDamage->id}", $this->damageForm())->assertNotFound();
        $this->postJson("/api/vehicle-damages/{$otherDamage->id}/repair", ['repaired_at' => '2026-10-20'])->assertNotFound();
    }

    // ---- Correcting ----

    public function test_staff_can_correct_an_open_damage(): void
    {
        $damage = $this->damage();
        $foundAt = $damage->found_at->toIso8601String();

        $this->actingAs($this->staff)
            ->putJson("/api/vehicle-damages/{$damage->id}", $this->damageForm(['size' => 'large']))
            ->assertOk()
            ->assertJsonPath('data.size', 'large');

        $this->assertSame($foundAt, $damage->fresh()->found_at->toIso8601String());
    }

    public function test_a_repaired_damage_cannot_be_edited(): void
    {
        $damage = VehicleDamage::factory()->for($this->car)->repaired()->create();

        $this->actingAs($this->staff)
            ->putJson("/api/vehicle-damages/{$damage->id}", $this->damageForm())
            ->assertConflict();
    }

    // ---- Repairing ----

    public function test_staff_can_mark_damage_as_repaired_with_its_cost(): void
    {
        $damage = $this->damage(['found_at' => now()->subDays(10)]);

        $this->actingAs($this->staff)
            ->postJson("/api/vehicle-damages/{$damage->id}/repair", [
                'repaired_at' => '2026-10-20',
                'repair_cost' => 400,
                'repair_note' => 'Body shop Maarif',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'repaired')
            ->assertJsonPath('data.repaired_at', '2026-10-20')
            ->assertJsonPath('data.repair_cost', '400.00');

        // Repaired damage leaves the drawing but stays in the history
        $this->getJson("/api/vehicles/{$this->car->id}/damages?status=open")->assertJsonCount(0, 'data');
        $this->getJson("/api/vehicles/{$this->car->id}/damages")->assertJsonCount(1, 'data');
    }

    public function test_the_repair_date_is_between_finding_and_today(): void
    {
        $damage = $this->damage(['found_at' => now()->subDays(10)]); // found on Oct 11

        $this->actingAs($this->staff);

        $this->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repaired_at' => 'The repair date cannot be before the damage was found.']);

        $this->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-22'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repaired_at' => 'The repair date cannot be in the future.']);

        $this->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-21', 'repair_cost' => 450.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('repair_cost');
    }

    public function test_a_damage_cannot_be_repaired_twice(): void
    {
        $damage = VehicleDamage::factory()->for($this->car)->repaired()->create();

        $this->actingAs($this->staff)
            ->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-21'])
            ->assertConflict();
    }

    public function test_only_the_owner_can_reopen_a_damage_marked_repaired_by_mistake(): void
    {
        $damage = VehicleDamage::factory()->for($this->car)->repaired()->create();

        $this->actingAs($this->staff)
            ->deleteJson("/api/vehicle-damages/{$damage->id}/repair")
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson("/api/vehicle-damages/{$damage->id}/repair")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.repair_cost', null);
    }

    // ---- Deleting ----

    public function test_only_the_owner_can_delete_a_damage_recorded_by_mistake(): void
    {
        $damage = $this->damage();

        $this->actingAs($this->staff)->deleteJson("/api/vehicle-damages/{$damage->id}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/vehicle-damages/{$damage->id}")->assertNoContent();

        $this->assertModelMissing($damage);
    }

    // ---- Footprints ----

    public function test_damage_leaves_footprints(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/damages", $this->damageForm());
        $damage = VehicleDamage::sole();
        $this->postJson("/api/vehicle-damages/{$damage->id}/repair", ['repaired_at' => '2026-10-21', 'repair_cost' => 400]);

        $logs = ActivityLog::where('subject_type', 'vehicle_damage')->orderBy('id')->get();

        $this->assertSame(['created', 'repaired'], $logs->pluck('action')->all());
        $this->assertSame('V-001 Scratch - Rear bumper', $logs[0]->subject_label);
        $this->assertSame('400.00', $logs[1]->diff['repair_cost']['new']);
    }
}
