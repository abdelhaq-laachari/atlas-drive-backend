<?php

namespace Tests\Feature\Models;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_agency_gets_the_agreed_default_rules(): void
    {
        $agency = Agency::create(['name' => 'Test Agency'])->fresh();

        $this->assertSame('MAD', $agency->currency);
        $this->assertSame('Africa/Casablanca', $agency->timezone);
        $this->assertSame(60, $agency->late_grace_minutes);
        $this->assertSame('150.00', $agency->fuel_price_per_quarter);
        $this->assertSame(21, $agency->driver_min_age);
        $this->assertSame(2, $agency->licence_min_years);
        $this->assertSame(30, $agency->document_expiring_soon_days);
    }

    public function test_each_agency_can_change_its_own_rules(): void
    {
        $agency = Agency::factory()->create([
            'late_grace_minutes' => 30,
            'fuel_price_per_quarter' => 120,
        ]);

        $this->assertSame(30, $agency->fresh()->late_grace_minutes);
        $this->assertSame('120.00', $agency->fresh()->fuel_price_per_quarter);
    }

    public function test_a_user_belongs_to_an_agency(): void
    {
        $agency = Agency::factory()->create();
        $user = User::factory()->for($agency)->create();

        $this->assertTrue($user->agency->is($agency));
        $this->assertTrue($agency->users->contains($user));
    }

    public function test_a_new_user_is_staff_unless_made_owner(): void
    {
        $staff = User::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->assertSame(Role::Staff, $staff->role);
        $this->assertFalse($staff->isOwner());
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertTrue($owner->isOwner());
    }

    public function test_role_and_agency_cannot_be_mass_assigned(): void
    {
        $user = new User([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret',
            'role' => 'owner',
            'agency_id' => 999,
        ]);

        $this->assertNull($user->role);
        $this->assertNull($user->agency_id);
    }
}
