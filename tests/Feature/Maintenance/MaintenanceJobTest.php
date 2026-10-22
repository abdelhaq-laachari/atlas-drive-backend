<?php

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Enums\VehicleStatus;
use App\Models\ActivityLog;
use App\Models\MaintenanceJob;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MaintenanceJobTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->parse('2026-10-22 10:00:00', 'Africa/Casablanca'));
        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create();
        $this->car = Vehicle::factory()->for($this->owner->agency)->create(['make' => 'Kia', 'model' => 'Picanto', 'mileage' => 38400]);
    }

    private function schedule(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/maintenance', array_merge([
            'vehicle_id' => $this->car->id,
            'type' => 'oil_change',
            'scheduled_for' => '2026-10-24',
            'garage' => 'Garage Atlas',
            'description' => 'Oil + filter',
        ], $overrides));
    }

    private function job(array $attributes = []): MaintenanceJob
    {
        return MaintenanceJob::factory()->for($this->car)->create(['agency_id' => $this->owner->agency_id, ...$attributes]);
    }

    // ---- Scheduling ----

    public function test_staff_schedule_a_job(): void
    {
        $this->schedule()
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.type', 'oil_change')
            ->assertJsonPath('data.type_label', 'Oil change')
            ->assertJsonPath('data.scheduled_for', '2026-10-24')
            ->assertJsonPath('data.vehicle.fleet_code', 'V-001');

        $this->assertSame(VehicleStatus::Available, $this->car->fresh()->status); // not at the garage yet
        $this->assertSame('created', ActivityLog::where('subject_type', 'maintenance_job')->sole()->action);
    }

    public function test_the_type_and_car_are_checked(): void
    {
        $otherAgencyCar = Vehicle::factory()->create(); // before anyone logs in (agency guard)

        $this->schedule(['type' => 'car_wash', 'scheduled_for' => '24/10/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'scheduled_for']);

        $this->schedule(['vehicle_id' => $otherAgencyCar->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id' => 'Not found.']);
    }

    public function test_status_and_dates_cannot_be_sent_in_the_form(): void
    {
        $this->schedule(['status' => 'done', 'completed_at' => '2026-10-01 10:00'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.completed_at', null);
    }

    // ---- Start → the car is at the garage ----

    public function test_starting_a_job_puts_the_car_in_maintenance(): void
    {
        $job = $this->job();

        $this->actingAs($this->staff)
            ->postJson("/api/maintenance/{$job->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.started_at', now()->setTimezone('Africa/Casablanca')->startOfMinute()->toIso8601String());

        $this->assertSame(VehicleStatus::Maintenance, $this->car->fresh()->status);
    }

    public function test_a_car_out_with_a_customer_cannot_go_to_the_garage(): void
    {
        Rental::factory()->for($this->owner->agency)->active()->create(['vehicle_id' => $this->car->id]);
        $job = $this->job();

        $this->actingAs($this->staff)
            ->postJson("/api/maintenance/{$job->id}/start")
            ->assertConflict();

        $this->assertSame(MaintenanceStatus::Scheduled, $job->fresh()->status);
    }

    public function test_starting_warns_about_bookings_waiting_for_the_car(): void
    {
        Rental::factory()->for($this->owner->agency)->create([
            'vehicle_id' => $this->car->id,
            'pickup_at' => now()->parse('2026-10-25 09:00', 'Africa/Casablanca'),
            'return_at' => now()->parse('2026-10-27 09:00', 'Africa/Casablanca'),
        ]);
        $job = $this->job();

        $this->actingAs($this->staff)
            ->postJson("/api/maintenance/{$job->id}/start")
            ->assertOk()
            ->assertJsonPath('meta.warnings.0', 'V-001 is booked from 25/10/2026 09:00 (R-0001).');
    }

    public function test_a_car_in_maintenance_cannot_be_handed_over(): void
    {
        $job = $this->job();
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$job->id}/start");

        $this->assertSame(VehicleStatus::Maintenance, $this->car->fresh()->status);
        // (the hand-over check refuses cars in maintenance — see RentalHandoverTest)
    }

    // ---- Finish → the car comes back ----

    public function test_finishing_records_cost_and_km_and_frees_the_car(): void
    {
        $job = $this->job();
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$job->id}/start");
        $this->travel(3)->hours();

        $this->postJson("/api/maintenance/{$job->id}/finish", ['mileage' => 38450, 'cost' => 450, 'description' => 'Oil 5W30 + filter'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.cost', '450.00')
            ->assertJsonPath('data.mileage', 38450)
            ->assertJsonPath('data.description', 'Oil 5W30 + filter');

        $this->car->refresh();
        $this->assertSame(VehicleStatus::Available, $this->car->status);
        $this->assertSame(38450, $this->car->mileage);
        $this->assertSame('completed', ActivityLog::where('subject_type', 'maintenance_job')->latest('id')->first()->action);
    }

    public function test_the_car_stays_reserved_when_a_booking_is_waiting(): void
    {
        Rental::factory()->for($this->owner->agency)->create(['vehicle_id' => $this->car->id]);
        $job = $this->job();
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$job->id}/start");

        $this->postJson("/api/maintenance/{$job->id}/finish")->assertOk();

        $this->assertSame(VehicleStatus::Reserved, $this->car->fresh()->status);
    }

    public function test_the_car_stays_at_the_garage_while_another_job_is_running(): void
    {
        $oil = $this->job();
        $brakes = $this->job(['type' => 'brakes']);
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$oil->id}/start");
        $this->postJson("/api/maintenance/{$brakes->id}/start");

        $this->postJson("/api/maintenance/{$oil->id}/finish")->assertOk();
        $this->assertSame(VehicleStatus::Maintenance, $this->car->fresh()->status);

        $this->postJson("/api/maintenance/{$brakes->id}/finish")->assertOk();
        $this->assertSame(VehicleStatus::Available, $this->car->fresh()->status);
    }

    public function test_work_already_done_can_be_recorded_in_one_go(): void
    {
        $job = $this->job();

        $this->actingAs($this->staff)
            ->postJson("/api/maintenance/{$job->id}/finish", ['at' => '2026-10-21 16:00', 'cost' => 300])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.started_at', now()->parse('2026-10-21 16:00', 'Africa/Casablanca')->toIso8601String());

        $this->assertSame(VehicleStatus::Available, $this->car->fresh()->status);
    }

    public function test_finish_checks_km_cost_and_times(): void
    {
        $job = $this->job();
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$job->id}/start");

        $this->postJson("/api/maintenance/{$job->id}/finish", ['mileage' => 30000, 'cost' => 450.5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'mileage' => "The km can't be lower than the car's current km (38400).",
                'cost',
            ]);

        $this->postJson("/api/maintenance/{$job->id}/finish", ['at' => '2026-10-22 09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['at' => 'The end cannot be before the start.']);

        $this->postJson("/api/maintenance/{$job->id}/finish", ['at' => '2026-10-23 09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['at' => 'This time cannot be in the future.']);
    }

    // ---- Cancel, edit, delete ----

    public function test_cancelling_a_started_job_brings_the_car_back(): void
    {
        $job = $this->job();
        $this->actingAs($this->staff)->postJson("/api/maintenance/{$job->id}/start");

        $this->postJson("/api/maintenance/{$job->id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson("/api/maintenance/{$job->id}/cancel", ['reason' => 'Garage closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Garage closed');

        $this->assertSame(VehicleStatus::Available, $this->car->fresh()->status);
        $this->postJson("/api/maintenance/{$job->id}/start")->assertConflict();
    }

    public function test_a_done_job_cost_can_be_corrected(): void
    {
        $job = $this->job(['status' => MaintenanceStatus::Done, 'cost' => 400]);

        $this->actingAs($this->staff)
            ->putJson("/api/maintenance/{$job->id}", ['type' => 'oil_change', 'cost' => 450, 'garage' => 'Garage Atlas'])
            ->assertOk()
            ->assertJsonPath('data.cost', '450.00');
    }

    public function test_only_the_owner_deletes_a_job_and_never_while_the_car_is_at_the_garage(): void
    {
        $job = $this->job();

        $this->actingAs($this->staff)->deleteJson("/api/maintenance/{$job->id}")->assertForbidden();

        $this->actingAs($this->owner)->postJson("/api/maintenance/{$job->id}/start");
        $this->deleteJson("/api/maintenance/{$job->id}")->assertConflict();

        $this->postJson("/api/maintenance/{$job->id}/cancel", ['reason' => 'Entered twice']);
        $this->deleteJson("/api/maintenance/{$job->id}")->assertNoContent();
        $this->assertModelMissing($job);
    }

    // ---- Lists and isolation ----

    public function test_the_list_filters_by_status_car_type_and_period(): void
    {
        $done = $this->job(['status' => MaintenanceStatus::Done, 'started_at' => now()->subDays(10), 'completed_at' => now()->subDays(10)]);
        $planned = $this->job(['type' => 'tyres', 'scheduled_for' => '2026-10-30']);
        $otherCar = MaintenanceJob::factory()->for(Vehicle::factory()->for($this->owner->agency))->create(['agency_id' => $this->owner->agency_id]);

        $this->actingAs($this->staff);
        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$done->id], $ids('/api/maintenance?status=done'));
        $this->assertSame([$planned->id], $ids('/api/maintenance?type=tyres'));
        $this->assertNotContains($otherCar->id, $ids("/api/maintenance?vehicle_id={$this->car->id}"));
        $this->assertSame([$done->id], $ids('/api/maintenance?from=2026-10-10&to=2026-10-15'));
        $this->assertSame([$planned->id], $ids('/api/maintenance?from=2026-10-29&to=2026-10-31'));
    }

    public function test_another_agency_job_is_not_found(): void
    {
        $other = MaintenanceJob::factory()->create();

        $this->actingAs($this->owner);
        $this->getJson("/api/maintenance/{$other->id}")->assertNotFound();
        $this->postJson("/api/maintenance/{$other->id}/start")->assertNotFound();
        $this->deleteJson("/api/maintenance/{$other->id}")->assertNotFound();
        $this->assertSame(0, collect($this->getJson('/api/maintenance')->json('data'))->count());
    }

    public function test_a_garage_invoice_can_be_attached(): void
    {
        Storage::fake('local');
        $job = $this->job();

        $this->actingAs($this->staff)
            ->post('/api/attachments', [
                'attachable_type' => 'maintenance_job',
                'attachable_id' => $job->id,
                'category' => 'repair_invoice',
                'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n%%EOF"),
            ], ['Accept' => 'application/json'])
            ->assertCreated();
    }
}
