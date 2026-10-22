<?php

namespace Tests\Feature\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Models\ActivityLog;
use App\Models\MaintenanceJob;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Today" = 22 Oct 2026. The car (V-001) is at 38,400 km.
 */
class MaintenancePlanTest extends TestCase
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
        $this->car = Vehicle::factory()->for($this->owner->agency)->create(['mileage' => 38400]);
    }

    private function addPlan(array $data): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/vehicles/{$this->car->id}/maintenance-plans", array_merge([
            'type' => 'oil_change',
        ], $data));
    }

    private function doneJob(string $completedLocal, ?int $mileage, string $type = 'oil_change'): MaintenanceJob
    {
        return MaintenanceJob::factory()->for($this->car)->create([
            'agency_id' => $this->owner->agency_id,
            'type' => $type,
            'status' => MaintenanceStatus::Done,
            'started_at' => now()->parse($completedLocal, 'Africa/Casablanca'),
            'completed_at' => now()->parse($completedLocal, 'Africa/Casablanca'),
            'mileage' => $mileage,
        ]);
    }

    // ---- Next due ----

    public function test_a_new_plan_starts_today_at_the_car_km(): void
    {
        $this->addPlan(['every_km' => 10000, 'every_months' => 12])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Oil change')
            ->assertJsonPath('data.last_done', ['date' => '2026-10-22', 'mileage' => 38400, 'from' => 'plan_start'])
            ->assertJsonPath('data.next_due', ['date' => '2027-10-22', 'mileage' => 48400])
            ->assertJsonPath('data.days_left', 365)
            ->assertJsonPath('data.km_left', 10000)
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_the_last_finished_job_is_the_starting_point(): void
    {
        $this->addPlan(['every_km' => 10000, 'every_months' => 12, 'start_date' => '2025-01-10', 'start_mileage' => 20000]);
        $this->doneJob('2026-03-15 11:00', 30000);
        $this->doneJob('2025-09-01 11:00', 25000); // older: ignored

        $this->getJson("/api/vehicles/{$this->car->id}/maintenance-plans")
            ->assertJsonPath('data.0.last_done', ['date' => '2026-03-15', 'mileage' => 30000, 'from' => 'last_job'])
            ->assertJsonPath('data.0.next_due', ['date' => '2027-03-15', 'mileage' => 40000])
            ->assertJsonPath('data.0.km_left', 1600)
            ->assertJsonPath('data.0.status', 'ok');
    }

    public function test_whichever_comes_first_km_or_months(): void
    {
        // 12 months not reached yet, but only 400 km left → due soon
        $this->addPlan(['every_km' => 10000, 'every_months' => 12, 'start_date' => '2026-06-01', 'start_mileage' => 29000]);

        $this->getJson("/api/vehicles/{$this->car->id}/maintenance-plans")
            ->assertJsonPath('data.0.km_left', 600)
            ->assertJsonPath('data.0.status', 'due_soon');
    }

    public function test_overdue_by_date_or_by_km(): void
    {
        $this->addPlan(['every_months' => 6, 'start_date' => '2026-04-01', 'start_mileage' => 30000]); // due 01/10
        $this->addPlan(['type' => 'tyres', 'every_km' => 5000, 'start_date' => '2026-10-01', 'start_mileage' => 33000]); // due at 38,000

        $rows = collect($this->getJson("/api/vehicles/{$this->car->id}/maintenance-plans")->json('data'))->keyBy('type');

        $this->assertSame('overdue', $rows['oil_change']['status']);
        $this->assertSame(-21, $rows['oil_change']['days_left']);
        $this->assertNull($rows['oil_change']['km_left']); // no km interval
        $this->assertSame('overdue', $rows['tyres']['status']);
        $this->assertSame(-400, $rows['tyres']['km_left']);
    }

    public function test_a_planned_job_is_shown_with_the_reminder(): void
    {
        $this->addPlan(['every_months' => 6, 'start_date' => '2026-04-01', 'start_mileage' => 30000]);
        $job = MaintenanceJob::factory()->for($this->car)->create(['agency_id' => $this->owner->agency_id, 'scheduled_for' => '2026-10-24']);

        $this->getJson("/api/vehicles/{$this->car->id}/maintenance-plans")
            ->assertJsonPath('data.0.status', 'overdue')
            ->assertJsonPath('data.0.planned_job.id', $job->id)
            ->assertJsonPath('data.0.planned_job.scheduled_for', '2026-10-24');
    }

    public function test_finishing_the_job_resets_the_reminder(): void
    {
        $this->addPlan(['every_km' => 10000, 'every_months' => 12, 'start_date' => '2025-09-01', 'start_mileage' => 28000]);
        $job = MaintenanceJob::factory()->for($this->car)->create(['agency_id' => $this->owner->agency_id]);

        $this->postJson("/api/maintenance/{$job->id}/finish", ['mileage' => 38450, 'cost' => 450])->assertOk();

        $this->getJson("/api/vehicles/{$this->car->id}/maintenance-plans")
            ->assertJsonPath('data.0.last_done.from', 'last_job')
            ->assertJsonPath('data.0.next_due', ['date' => '2027-10-22', 'mileage' => 48450])
            ->assertJsonPath('data.0.status', 'ok')
            ->assertJsonPath('data.0.planned_job', null);
    }

    // ---- Fleet list (dashboard) ----

    public function test_the_upcoming_list_shows_what_needs_attention_most_urgent_first(): void
    {
        $soon = Vehicle::factory()->for($this->owner->agency)->create(['mileage' => 50000]);
        $fine = Vehicle::factory()->for($this->owner->agency)->create(['mileage' => 10000]);
        $sold = Vehicle::factory()->for($this->owner->agency)->archived()->create(['mileage' => 90000]);

        foreach ([[$this->car, '2026-04-01', 30000], [$soon, '2025-11-01', 45000], [$fine, '2026-10-01', 9000], [$sold, '2024-01-01', 10000]] as [$car, $date, $km]) {
            MaintenancePlan::factory()->for($car)->create([
                'agency_id' => $this->owner->agency_id, 'every_months' => 12, 'every_km' => null,
                'start_date' => $date, 'start_mileage' => $km,
            ]);
        }
        MaintenancePlan::where('vehicle_id', $this->car->id)->update(['every_months' => 6]); // due 01/10 → overdue

        $this->actingAs($this->staff);

        $this->getJson('/api/maintenance/upcoming')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.vehicle.id', $this->car->id)
            ->assertJsonPath('data.0.status', 'overdue')
            ->assertJsonPath('data.1.vehicle.id', $soon->id)
            ->assertJsonPath('data.1.status', 'due_soon')
            ->assertJsonPath('data.1.days_left', 10);

        $this->getJson('/api/maintenance/upcoming?all=1')->assertJsonCount(3, 'data'); // never the archived car
    }

    public function test_the_upcoming_list_only_shows_the_own_agency(): void
    {
        MaintenancePlan::factory()->create(['every_months' => 1, 'start_date' => '2025-01-01']); // another agency, overdue

        $this->actingAs($this->staff)->getJson('/api/maintenance/upcoming?all=1')->assertJsonCount(0, 'data');
    }

    // ---- Rules ----

    public function test_a_plan_needs_km_or_months_and_one_plan_per_type(): void
    {
        $this->addPlan([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['every_km', 'every_months']);

        $this->addPlan(['every_km' => 10000])->assertCreated();

        $this->addPlan(['every_km' => 15000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'V-001 already has a plan for this. Edit it instead.']);
    }

    public function test_the_starting_point_cannot_be_in_the_future_or_above_the_car_km(): void
    {
        $this->addPlan(['every_km' => 10000, 'start_date' => '2026-11-01', 'start_mileage' => 50000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'start_mileage']);
    }

    public function test_plans_can_be_edited_and_only_the_owner_deletes_them(): void
    {
        $plan = $this->addPlan(['every_km' => 10000])->json('data.plan_id');

        $this->putJson("/api/maintenance-plans/{$plan}", ['every_km' => 15000, 'start_date' => '2026-10-01', 'start_mileage' => 37000])
            ->assertOk()
            ->assertJsonPath('data.every_km', 15000)
            ->assertJsonPath('data.next_due.mileage', 52000);

        $this->deleteJson("/api/maintenance-plans/{$plan}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/api/maintenance-plans/{$plan}")->assertNoContent();

        $this->assertSame(['created', 'updated', 'deleted'], ActivityLog::where('subject_type', 'maintenance_plan')->orderBy('id')->pluck('action')->all());
    }

    public function test_another_agency_car_or_plan_is_not_found(): void
    {
        $otherCar = Vehicle::factory()->create();
        $otherPlan = MaintenancePlan::factory()->create();

        $this->actingAs($this->owner);
        $this->postJson("/api/vehicles/{$otherCar->id}/maintenance-plans", ['type' => 'oil_change', 'every_km' => 10000])->assertNotFound();
        $this->putJson("/api/maintenance-plans/{$otherPlan->id}", ['every_km' => 1000, 'start_date' => '2026-01-01', 'start_mileage' => 0])->assertNotFound();
        $this->deleteJson("/api/maintenance-plans/{$otherPlan->id}")->assertNotFound();
    }
}
