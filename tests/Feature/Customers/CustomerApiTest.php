<?php

namespace Tests\Feature\Customers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function customerForm(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Amine',
            'last_name' => 'Kettani',
            'phone' => '+212 600-000001',
            'email' => 'amine.kettani@example.com',
            'address' => null,
            'city' => 'Casablanca',
            'id_type' => 'cin',
            'id_number' => 'XX100001',
            'nationality' => 'Moroccan',
            'birth_date' => '1990-04-12',
            'licence_number' => 'XX-LIC-001',
            'licence_issue_date' => '2010-06-01',
            'notes' => null,
        ], $overrides);
    }

    private function customer(array $attributes = [], ?User $of = null): Customer
    {
        return Customer::factory()->for(($of ?? $this->owner)->agency)->create($attributes);
    }

    // ---- Adding ----

    public function test_staff_can_add_a_customer(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/customers', $this->customerForm())
            ->assertCreated()
            ->assertJsonPath('data.customer_code', 'C-001')
            ->assertJsonPath('data.full_name', 'Amine Kettani')
            ->assertJsonPath('data.type', 'individual')
            ->assertJsonPath('data.phone', '+212600000001')
            ->assertJsonPath('data.is_blocked', false);
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/customers', $this->customerForm())->assertUnauthorized();
    }

    public function test_customer_codes_are_numbered_per_agency(): void
    {
        $this->customer([], User::factory()->create());

        $this->actingAs($this->staff)
            ->postJson('/api/customers', $this->customerForm())
            ->assertJsonPath('data.customer_code', 'C-001');
    }

    public function test_the_same_id_number_cannot_be_used_twice_in_an_agency(): void
    {
        $this->customer(['id_number' => 'XX100001', 'first_name' => 'Amine', 'last_name' => 'Kettani']);

        $this->actingAs($this->staff)
            ->postJson('/api/customers', $this->customerForm(['id_number' => 'xx 100 001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_number' => 'This ID number already belongs to customer C-001 (Amine Kettani).']);
    }

    public function test_another_agency_may_have_the_same_person(): void
    {
        $this->customer(['id_number' => 'XX100001'], User::factory()->create());

        $this->actingAs($this->staff)
            ->postJson('/api/customers', $this->customerForm())
            ->assertCreated();
    }

    public function test_required_fields_and_formats_are_checked(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone', 'id_type', 'id_number', 'nationality', 'birth_date']);

        $this->postJson('/api/customers', $this->customerForm([
            'phone' => '12-34',
            'email' => 'not-an-email',
            'id_type' => 'driving-licence',
            'birth_date' => now()->addDay()->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'email', 'id_type', 'birth_date']);
    }

    public function test_licence_number_and_date_go_together_and_the_licence_is_after_birth(): void
    {
        $this->actingAs($this->staff);

        $this->postJson('/api/customers', $this->customerForm(['licence_issue_date' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('licence_issue_date');

        $this->postJson('/api/customers', $this->customerForm(['licence_issue_date' => '1985-01-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['licence_issue_date' => 'The licence date must be after the birth date.']);

        // A customer who pays but doesn't drive may have no licence
        $this->postJson('/api/customers', $this->customerForm(['licence_number' => null, 'licence_issue_date' => null]))
            ->assertCreated();
    }

    public function test_type_status_and_agency_cannot_be_sent_in_the_form(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/customers', $this->customerForm([
                'type' => 'company', 'blocked_at' => now(), 'agency_id' => User::factory()->create()->agency_id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'individual')
            ->assertJsonPath('data.is_blocked', false);

        $this->assertDatabaseHas('customers', ['id_key' => 'XX100001', 'agency_id' => $this->owner->agency_id]);
    }

    // ---- Editing ----

    public function test_staff_can_edit_a_customer_and_keep_their_own_id_number(): void
    {
        $customer = $this->customer(['id_number' => 'XX100001']);

        $this->actingAs($this->staff)
            ->putJson("/api/customers/{$customer->id}", $this->customerForm(['city' => 'Rabat']))
            ->assertOk()
            ->assertJsonPath('data.city', 'Rabat');
    }

    public function test_an_archived_customer_cannot_be_edited(): void
    {
        $customer = Customer::factory()->for($this->owner->agency)->archived()->create();

        $this->actingAs($this->staff)
            ->putJson("/api/customers/{$customer->id}", $this->customerForm(['id_number' => $customer->id_number]))
            ->assertConflict();
    }

    // ---- Listing and searching ----

    public function test_the_list_is_sorted_by_name_and_only_shows_the_own_agency(): void
    {
        $this->customer(['first_name' => 'Sara', 'last_name' => 'Lahlou']);
        $this->customer(['first_name' => 'Karim', 'last_name' => 'Bennani']);
        $this->customer([], User::factory()->create());

        $this->actingAs($this->staff)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.last_name', 'Bennani')
            ->assertJsonPath('data.1.last_name', 'Lahlou');
    }

    public function test_customers_can_be_searched_by_name_phone_id_or_code(): void
    {
        $this->customer(['first_name' => 'Amine', 'last_name' => 'Kettani', 'phone' => '+212600000001', 'id_number' => 'XX100001']);
        $this->customer(['first_name' => 'Nadia', 'last_name' => 'El Idrissi', 'phone' => '+212611223344', 'id_number' => 'BE987654']);

        $this->actingAs($this->staff);

        foreach (['Nadia', 'el idr', 'Idrissi Nadia', '0611 22 33', '+212 611 22', '00212611', 'be987654', 'C-002', 'c2'] as $search) {
            $this->getJson('/api/customers?search='.urlencode($search))
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.first_name', 'Nadia');
        }
    }

    public function test_blocked_and_archived_filters(): void
    {
        $this->customer(['last_name' => 'Active']);
        Customer::factory()->for($this->owner->agency)->blocked()->create(['last_name' => 'Blocked']);
        Customer::factory()->for($this->owner->agency)->archived()->create(['last_name' => 'Archived']);

        $this->actingAs($this->staff);

        $this->getJson('/api/customers')->assertJsonCount(2, 'data');
        $this->getJson('/api/customers?blocked=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Blocked');
        $this->getJson('/api/customers?blocked=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Active');
        $this->getJson('/api/customers?archived=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Archived');
    }

    public function test_another_agency_customer_is_not_found(): void
    {
        $other = $this->customer([], User::factory()->create());

        $this->actingAs($this->owner);

        $this->getJson("/api/customers/{$other->id}")->assertNotFound();
        $this->putJson("/api/customers/{$other->id}", $this->customerForm())->assertNotFound();
    }

    // ---- Privacy ----

    public function test_id_and_licence_numbers_are_hidden_in_the_footprints(): void
    {
        $this->actingAs($this->staff)->postJson('/api/customers', $this->customerForm());
        $customer = Customer::sole();
        $customer->update(['id_number' => 'XX999999', 'city' => 'Rabat']);

        $logs = ActivityLog::where('subject_type', 'customer')->orderBy('id')->get();

        $this->assertSame('C-001 Amine Kettani', $logs[0]->subject_label);
        $this->assertEquals(['old' => '(hidden)', 'new' => '(hidden)'], $logs[0]->diff['id_number']);
        $this->assertEquals(['old' => '(hidden)', 'new' => '(hidden)'], $logs[0]->diff['licence_number']);
        $this->assertArrayNotHasKey('id_key', $logs[0]->diff);
        $this->assertEquals(['old' => '(hidden)', 'new' => '(hidden)'], $logs[1]->diff['id_number']);
        $this->assertSame('Rabat', $logs[1]->diff['city']['new']);
        $this->assertStringNotContainsString('XX999999', json_encode($logs->pluck('diff')));
    }
}
