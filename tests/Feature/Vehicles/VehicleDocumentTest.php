<?php

namespace Tests\Feature\Vehicles;

use App\Enums\DocumentType;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleDocumentTest extends TestCase
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
    private function insuranceForm(array $overrides = []): array
    {
        return array_merge([
            'type' => 'insurance',
            'reference' => 'POL-12345',
            'provider' => 'Wafa Assurance',
            'issue_date' => '2026-10-01',
            'expiry_date' => '2027-09-30',
            'cost' => 5500,
            'notes' => null,
        ], $overrides);
    }

    private function document(array $attributes = [], ?Vehicle $car = null): VehicleDocument
    {
        return VehicleDocument::factory()->for($car ?? $this->car)->create($attributes);
    }

    // ---- Adding and renewing ----

    public function test_staff_can_add_a_document(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm())
            ->assertCreated()
            ->assertJsonPath('data.type', 'insurance')
            ->assertJsonPath('data.expiry_date', '2027-09-30')
            ->assertJsonPath('data.cost', '5500.00');

        $this->assertDatabaseHas('vehicle_documents', [
            'vehicle_id' => $this->car->id,
            'agency_id' => $this->owner->agency_id,
        ]);
    }

    public function test_renewing_keeps_the_old_document_as_history(): void
    {
        $old = $this->document(['issue_date' => '2025-10-01', 'expiry_date' => '2026-09-30']);

        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm());

        $this->getJson("/api/vehicles/{$this->car->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'insurance')
            ->assertJsonPath('data.0.status', 'valid')
            ->assertJsonPath('data.0.current.reference', 'POL-12345')
            ->assertJsonPath('data.0.history.0.id', $old->id);
    }

    public function test_insurance_needs_an_expiry_date_after_the_start_date(): void
    {
        $this->actingAs($this->staff);

        $this->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm(['expiry_date' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expiry_date');

        $this->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm(['expiry_date' => '2026-09-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expiry_date' => 'The expiry date must be after the start date.']);
    }

    public function test_a_registration_card_has_no_expiry_date(): void
    {
        $this->actingAs($this->staff);

        $this->postJson("/api/vehicles/{$this->car->id}/documents", [
            'type' => 'registration', 'issue_date' => '2023-03-15', 'expiry_date' => '2033-03-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('expiry_date');

        $this->postJson("/api/vehicles/{$this->car->id}/documents", [
            'type' => 'registration', 'reference' => 'CG-1234', 'issue_date' => '2023-03-15',
        ])->assertCreated()->assertJsonPath('data.expiry_date', null);
    }

    public function test_the_cost_is_whole_dirhams_and_the_type_must_be_known(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm(['cost' => 5500.5, 'type' => 'passport']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cost', 'type']);
    }

    public function test_no_document_can_be_added_to_an_archived_car(): void
    {
        $archived = Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->owner)
            ->postJson("/api/vehicles/{$archived->id}/documents", $this->insuranceForm())
            ->assertConflict();
    }

    public function test_another_agency_car_or_document_is_not_found(): void
    {
        $otherCar = Vehicle::factory()->create();
        $otherDocument = $this->document([], $otherCar);

        $this->actingAs($this->owner);

        $this->getJson("/api/vehicles/{$otherCar->id}/documents")->assertNotFound();
        $this->postJson("/api/vehicles/{$otherCar->id}/documents", $this->insuranceForm())->assertNotFound();
        $this->putJson("/api/vehicle-documents/{$otherDocument->id}", $this->insuranceForm())->assertNotFound();
        $this->deleteJson("/api/vehicle-documents/{$otherDocument->id}")->assertNotFound();
    }

    // ---- Correcting and deleting ----

    public function test_staff_can_correct_a_document(): void
    {
        $document = $this->document();

        $this->actingAs($this->staff)
            ->putJson("/api/vehicle-documents/{$document->id}", $this->insuranceForm(['reference' => 'POL-99999']))
            ->assertOk()
            ->assertJsonPath('data.reference', 'POL-99999');
    }

    public function test_only_the_owner_can_delete_a_document(): void
    {
        $document = $this->document();

        $this->actingAs($this->staff)
            ->deleteJson("/api/vehicle-documents/{$document->id}")
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson("/api/vehicle-documents/{$document->id}")
            ->assertNoContent();

        $this->assertModelMissing($document);
    }

    public function test_documents_leave_footprints(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/vehicles/{$this->car->id}/documents", $this->insuranceForm());

        $log = ActivityLog::where('subject_type', 'vehicle_document')->sole();

        $this->assertSame('created', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame('V-001 Insurance POL-12345', $log->subject_label);
    }

    // ---- Documents & Deadlines (whole fleet) ----

    public function test_the_fleet_list_shows_the_most_urgent_first(): void
    {
        $secondCar = Vehicle::factory()->for($this->owner->agency)->create();
        foreach (DocumentType::cases() as $type) {
            $this->document(['type' => $type, 'expiry_date' => $type->hasExpiry() ? '2027-06-01' : null]);
        }
        VehicleDocument::factory()->for($secondCar)->expiresIn(12)->create();
        VehicleDocument::factory()->for($secondCar)->type(DocumentType::Inspection)->expiresIn(-3)->create();

        $this->actingAs($this->staff)
            ->getJson('/api/vehicle-documents?status=expired,missing,expiring')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.type', 'inspection')
            ->assertJsonPath('data.0.days_left', -3)
            ->assertJsonPath('data.0.vehicle.fleet_code', 'V-002')
            ->assertJsonPath('data.1.status', 'missing')
            ->assertJsonPath('data.3.status', 'expiring')
            ->assertJsonPath('data.3.days_left', 12);
    }

    public function test_the_fleet_list_can_be_filtered_by_type_and_skips_archived_cars(): void
    {
        Vehicle::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->staff)
            ->getJson('/api/vehicle-documents?type=insurance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle.id', $this->car->id)
            ->assertJsonPath('data.0.status', 'missing');

        $this->getJson('/api/vehicle-documents?status=lost')->assertUnprocessable();
    }

    public function test_the_fleet_list_only_shows_the_own_agency_cars(): void
    {
        Vehicle::factory()->create(); // another agency

        $this->actingAs($this->staff)
            ->getJson('/api/vehicle-documents')
            ->assertJsonCount(4, 'data'); // 1 car x 4 document types
    }
}
