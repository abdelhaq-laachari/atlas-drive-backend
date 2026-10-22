<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Karim Owner']);
        $this->staff = User::factory()->for($this->owner->agency)->create(['name' => 'Youssef', 'email' => 'youssef@atlasdrive.ma']);
    }

    private function login(string $email, string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * A fake open session of this user in the sessions table (like a browser that is still logged in).
     */
    private function openSessionFor(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-'.$user->id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
    }

    // ---- Who can manage ----

    public function test_only_the_owner_manages_staff(): void
    {
        $this->actingAs($this->staff)->getJson('/api/settings/users')->assertForbidden();
        $this->actingAs($this->staff)->postJson('/api/settings/users', ['name' => 'X', 'email' => 'x@x.ma', 'role' => 'owner'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/settings/users')->assertUnauthorized();
    }

    public function test_the_owner_sees_only_their_agency_users_without_secrets(): void
    {
        User::factory()->create(); // another agency

        $response = $this->actingAs($this->owner)
            ->getJson('/api/settings/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Karim Owner')
            ->assertJsonPath('data.0.is_me', true);

        $response->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.1.password');
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->assertStringNotContainsString($this->staff->password, $response->getContent());
    }

    public function test_another_agency_user_does_not_exist_for_this_owner(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner);
        $this->putJson("/api/settings/users/{$stranger->id}", ['name' => 'Hacked', 'email' => 'h@h.ma', 'role' => 'staff'])->assertNotFound();
        $this->postJson("/api/settings/users/{$stranger->id}/deactivate")->assertNotFound();
        $this->postJson("/api/settings/users/{$stranger->id}/reset-password")->assertNotFound();

        $this->assertTrue($stranger->fresh()->isActive());
    }

    // ---- Creating staff ----

    public function test_the_owner_creates_a_staff_member_with_a_temporary_password_shown_once(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/settings/users', ['name' => 'Sara', 'email' => 'sara@atlasdrive.ma', 'role' => 'staff'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.must_change_password', true)
            ->assertHeader('Cache-Control', 'no-store, private');

        $temporary = $response->json('meta.temporary_password');
        $this->assertSame(12, strlen($temporary));

        $sara = User::where('email', 'sara@atlasdrive.ma')->sole();
        $this->assertSame($this->owner->agency_id, $sara->agency_id);
        $this->assertNotSame($temporary, $sara->password); // stored hashed
        $this->assertTrue(Hash::check($temporary, $sara->password));

        // She can log in with it (and will have to change it: step 3)
        $this->login('sara@atlasdrive.ma', $temporary)->assertOk();
    }

    public function test_the_temporary_password_never_reaches_the_footprints(): void
    {
        $temporary = $this->actingAs($this->owner)
            ->postJson('/api/settings/users', ['name' => 'Sara', 'email' => 'sara@atlasdrive.ma', 'role' => 'staff'])
            ->json('meta.temporary_password');

        $log = ActivityLog::where('subject_type', 'user')->sole();
        $this->assertSame('created', $log->action);
        $this->assertSame('User Sara', $log->subject_label);
        $this->assertEquals(['old' => '(hidden)', 'new' => '(hidden)'], $log->diff['password']);
        $this->assertStringNotContainsString($temporary, json_encode($log->diff));
    }

    public function test_emails_are_unique_and_roles_known(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/settings/users', ['name' => 'Copy', 'email' => 'youssef@atlasdrive.ma', 'role' => 'boss'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This email is already used by another account.', 'role']);
    }

    // ---- Editing and the last owner ----

    public function test_the_owner_edits_a_staff_member(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/settings/users/{$this->staff->id}", ['name' => 'Youssef A.', 'email' => 'y.a@atlasdrive.ma', 'role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame('role_changed', ActivityLog::where('subject_type', 'user')->latest('id')->first()->action);
    }

    public function test_the_last_active_owner_cannot_become_staff(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/settings/users/{$this->owner->id}", ['name' => 'Karim', 'email' => $this->owner->email, 'role' => 'staff'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'The agency needs at least one active owner.']);

        // With a second active owner it is fine
        $this->staff->forceFill(['role' => Role::Owner])->save();
        $this->putJson("/api/settings/users/{$this->owner->id}", ['name' => 'Karim', 'email' => $this->owner->email, 'role' => 'staff'])
            ->assertOk();
    }

    // ---- Deactivating ----

    public function test_a_deactivated_staff_member_is_locked_out_everywhere(): void
    {
        $this->openSessionFor($this->staff);
        $oldRememberToken = $this->staff->remember_token;

        $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->staff->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Open sessions deleted, "remember me" cancelled
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->staff->id)->count());
        $this->assertNotSame($oldRememberToken, $this->staff->fresh()->remember_token);

        // Still has the app open → thrown out at the next request
        $this->actingAs($this->staff->fresh())->getJson('/api/vehicles')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This account is deactivated.');

        // Can't log in again, even with the right password
        $this->login('youssef@atlasdrive.ma', 'password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This account is deactivated. Contact your agency owner.']);

        $this->assertSame('deactivated', ActivityLog::where('subject_type', 'user')->latest('id')->first()->action);
    }

    public function test_an_owner_cannot_deactivate_themselves_or_the_last_owner(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->owner->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => "You can't deactivate your own account."]);

        // A second owner tries to deactivate the only other active owner... after the first one left
        $secondOwner = User::factory()->owner()->for($this->owner->agency)->create();
        $this->owner->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($secondOwner)
            ->postJson("/api/settings/users/{$secondOwner->id}/deactivate")
            ->assertUnprocessable();
    }

    public function test_a_deactivated_staff_member_can_be_reactivated(): void
    {
        $this->staff->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->staff->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->login('youssef@atlasdrive.ma', 'password')->assertOk();
        $this->actingAs($this->owner)->postJson("/api/settings/users/{$this->staff->id}/reactivate")->assertConflict();
    }

    // ---- Resetting a password ----

    public function test_the_owner_resets_a_forgotten_password(): void
    {
        $this->openSessionFor($this->staff);

        $temporary = $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->staff->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('meta.temporary_password');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->staff->id)->count());
        $this->login('youssef@atlasdrive.ma', 'password')->assertUnprocessable(); // old one is dead
        $this->login('youssef@atlasdrive.ma', $temporary)->assertOk();

        $log = ActivityLog::where('subject_type', 'user')->where('action', 'password_reset')->sole();
        $this->assertStringNotContainsString($temporary, json_encode($log->diff));
    }

    public function test_an_owner_resets_their_own_password_elsewhere(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->owner->id}/reset-password")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'Change your own password from "My account".']);
    }

    // ---- Login remembers the last login ----

    public function test_the_last_login_time_is_recorded_without_a_footprint(): void
    {
        $this->login('youssef@atlasdrive.ma', 'password')->assertOk();

        $this->assertNotNull($this->staff->fresh()->last_login_at);
        $this->assertSame(0, ActivityLog::where('subject_type', 'user')->count());
    }
}
