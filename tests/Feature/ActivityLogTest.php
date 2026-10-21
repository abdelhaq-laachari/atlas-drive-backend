<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Owner']);
        $this->staff = User::factory()->for($this->owner->agency)->create(['name' => 'Youssef']);
    }

    private function car(array $attributes = []): Vehicle
    {
        return Vehicle::factory()->for($this->owner->agency)->create($attributes);
    }

    // ---- What is recorded ----

    public function test_adding_a_car_records_who_added_it(): void
    {
        $this->actingAs($this->owner);
        $car = $this->car(['make' => 'Dacia', 'model' => 'Logan']);

        $log = ActivityLog::sole();

        $this->assertSame('created', $log->action);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('vehicle', $log->subject_type);
        $this->assertSame($car->id, $log->subject_id);
        $this->assertSame('V-001 Dacia Logan', $log->subject_label);
        $this->assertSame('Logan', $log->diff['model']['new']);
        $this->assertArrayNotHasKey('plate_key', $log->diff);
    }

    public function test_editing_records_only_what_changed_with_old_and_new_values(): void
    {
        $car = $this->car(['daily_price' => 270, 'mileage' => 61200]);

        $this->actingAs($this->staff);
        $car->update(['daily_price' => 250]);

        $log = ActivityLog::latest('id')->first();

        $this->assertSame('updated', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame(['daily_price'], array_keys($log->diff));
        $this->assertSame('270.00', $log->diff['daily_price']['old']);
    }

    public function test_saving_without_changes_records_nothing(): void
    {
        $car = $this->car();
        $before = ActivityLog::count();

        $car->update(['mileage' => $car->mileage]);

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_status_change_archive_and_restore_have_clear_actions(): void
    {
        $car = $this->car();

        $this->actingAs($this->staff)
            ->patchJson("/api/vehicles/{$car->id}/status", ['status' => 'maintenance']);
        $this->actingAs($this->owner)
            ->postJson("/api/vehicles/{$car->id}/archive", ['reason' => 'Sold']);
        $this->deleteJson("/api/vehicles/{$car->id}/archive");

        $actions = ActivityLog::where('subject_id', $car->id)->orderBy('id')->pluck('action')->all();

        $this->assertSame(['created', 'status_changed', 'archived', 'restored'], $actions);

        $archived = ActivityLog::where('action', 'archived')->sole();
        $this->assertSame('Sold', $archived->diff['archive_reason']['new']);
        $this->assertSame($this->owner->id, $archived->user_id);
    }

    public function test_actions_with_nobody_logged_in_are_recorded_as_system(): void
    {
        $this->car();

        $this->assertNull(ActivityLog::sole()->user_id);
    }

    // ---- The log is read-only ----

    public function test_a_footprint_cannot_be_edited(): void
    {
        $this->car();

        $log = ActivityLog::sole();
        $log->action = 'nothing happened';

        $this->expectException(LogicException::class);
        $log->save();
    }

    public function test_a_footprint_cannot_be_deleted(): void
    {
        $this->car();

        $this->expectException(LogicException::class);
        ActivityLog::sole()->delete();
    }

    // ---- Reading it ----

    public function test_the_owner_can_read_the_footprints_newest_first(): void
    {
        $this->actingAs($this->staff);
        $car = $this->car(['make' => 'Dacia', 'model' => 'Logan', 'daily_price' => 270]);
        $car->update(['daily_price' => 250]);

        $this->actingAs($this->owner)
            ->getJson('/api/activity')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.user.name', 'Youssef')
            ->assertJsonPath('data.0.subject.label', 'V-001 Dacia Logan')
            ->assertJsonPath('data.0.changes.daily_price.new', '250.00')
            ->assertJsonPath('data.1.action', 'created');
    }

    public function test_staff_cannot_read_the_footprints(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/activity')
            ->assertForbidden();
    }

    public function test_an_owner_only_sees_their_own_agency_footprints(): void
    {
        $this->car();
        Vehicle::factory()->create(); // another agency's car

        $this->actingAs($this->owner)
            ->getJson('/api/activity')
            ->assertJsonCount(1, 'data');
    }

    public function test_footprints_can_be_filtered_by_staff_member_and_by_car(): void
    {
        $this->actingAs($this->staff);
        $carA = $this->car();
        $this->actingAs($this->owner);
        $carB = $this->car();

        $this->getJson("/api/activity?user_id={$this->staff->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.id', $carA->id);

        $this->getJson("/api/activity?subject_type=vehicle&subject_id={$carB->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', 'Owner');
    }

    public function test_footprints_can_be_filtered_by_day_in_the_agency_timezone(): void
    {
        // 23:30 in Casablanca (UTC+1) on Oct 20 = 22:30 UTC on Oct 20
        $this->travelTo(now()->parse('2026-10-20 22:30:00', 'UTC'));
        $this->car();
        $this->travelBack();

        $this->actingAs($this->owner);

        $this->getJson('/api/activity?from=2026-10-20&to=2026-10-20')->assertJsonCount(1, 'data');
        $this->getJson('/api/activity?from=2026-10-21&to=2026-10-21')->assertJsonCount(0, 'data');
    }
}
