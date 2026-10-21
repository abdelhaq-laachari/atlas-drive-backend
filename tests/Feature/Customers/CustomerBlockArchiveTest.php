<?php

namespace Tests\Feature\Customers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBlockArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create();
        $this->customer = Customer::factory()->for($this->owner->agency)->create();
    }

    // ---- Blocklist ----

    public function test_the_owner_can_block_a_customer_with_a_reason(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/customers/{$this->customer->id}/block", ['reason' => 'Unpaid damage'])
            ->assertOk()
            ->assertJsonPath('data.is_blocked', true)
            ->assertJsonPath('data.block_reason', 'Unpaid damage');

        $this->assertSame('blocked', ActivityLog::latest('id')->first()->action);
    }

    public function test_a_reason_is_required_to_block(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/customers/{$this->customer->id}/block", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_staff_cannot_block_or_unblock(): void
    {
        $blocked = Customer::factory()->for($this->owner->agency)->blocked()->create();

        $this->actingAs($this->staff);

        $this->postJson("/api/customers/{$this->customer->id}/block", ['reason' => 'x'])->assertForbidden();
        $this->deleteJson("/api/customers/{$blocked->id}/block")->assertForbidden();
    }

    public function test_the_owner_can_unblock_a_customer(): void
    {
        $blocked = Customer::factory()->for($this->owner->agency)->blocked()->create();

        $this->actingAs($this->owner)
            ->deleteJson("/api/customers/{$blocked->id}/block")
            ->assertOk()
            ->assertJsonPath('data.is_blocked', false)
            ->assertJsonPath('data.block_reason', null);

        $this->assertSame('unblocked', ActivityLog::latest('id')->first()->action);
    }

    public function test_blocking_twice_or_unblocking_a_free_customer_is_refused(): void
    {
        $blocked = Customer::factory()->for($this->owner->agency)->blocked()->create();

        $this->actingAs($this->owner);

        $this->postJson("/api/customers/{$blocked->id}/block", ['reason' => 'again'])->assertConflict();
        $this->deleteJson("/api/customers/{$this->customer->id}/block")->assertConflict();
    }

    // ---- Archive ----

    public function test_the_owner_can_archive_and_restore_a_customer(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/customers/{$this->customer->id}/archive", ['reason' => 'Duplicate of C-004'])
            ->assertOk()
            ->assertJsonPath('data.archive_reason', 'Duplicate of C-004');

        $this->getJson('/api/customers')->assertJsonCount(0, 'data');

        $this->deleteJson("/api/customers/{$this->customer->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);

        $this->assertSame(['created', 'archived', 'restored'], ActivityLog::orderBy('id')->pluck('action')->all());
    }

    public function test_staff_cannot_archive_a_customer(): void
    {
        $this->actingAs($this->staff)
            ->postJson("/api/customers/{$this->customer->id}/archive", ['reason' => 'x'])
            ->assertForbidden();
    }

    public function test_customers_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson("/api/customers/{$this->customer->id}")
            ->assertMethodNotAllowed();

        $this->assertModelExists($this->customer);
    }
}
