<?php

namespace Tests\Feature\Settings;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Atlas2026Drive';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['email' => 'youssef@atlasdrive.ma']); // password: "password"
    }

    private function changePassword(array $overrides = []): TestResponse
    {
        return $this->putJson('/api/account/password', array_merge([
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    // ---- Changing my password ----

    public function test_a_user_changes_their_own_password(): void
    {
        $this->actingAs($this->staff)->changePassword()->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->staff->fresh()->password));

        $log = ActivityLog::where('subject_type', 'user')->sole();
        $this->assertSame('password_changed', $log->action);
        $this->assertEquals(['old' => '(hidden)', 'new' => '(hidden)'], $log->diff['password']);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($log->diff));
    }

    public function test_the_current_password_is_required(): void
    {
        $this->actingAs($this->staff)
            ->changePassword(['current_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'Your current password is not correct.']);
    }

    public function test_weak_or_unconfirmed_passwords_are_refused(): void
    {
        $this->actingAs($this->staff);

        foreach (['short1A', 'alllowercase2026', 'ALLUPPERCASE2026', 'NoNumbersHere', 'password'] as $weak) {
            $this->changePassword(['password' => $weak, 'password_confirmation' => $weak])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('password');
        }

        $this->changePassword(['password_confirmation' => 'Something2026Else'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_other_devices_are_logged_out_but_this_one_stays(): void
    {
        DB::table('sessions')->insert([
            'id' => 'phone-session', 'user_id' => $this->staff->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'phone', 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        $oldRememberToken = $this->staff->remember_token;

        // Log in like the browser (session cookie)
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'youssef@atlasdrive.ma', 'password' => 'password'])->assertOk();

        $this->changePassword()->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('id', 'phone-session')->count());
        $this->assertNotSame($oldRememberToken, $this->staff->fresh()->remember_token);
        $this->getJson('/api/me')->assertOk(); // still logged in here
    }

    public function test_too_many_attempts_are_slowed_down(): void
    {
        $this->actingAs($this->staff);

        foreach (range(1, 6) as $attempt) {
            $this->changePassword(['current_password' => 'guess'.$attempt])->assertUnprocessable();
        }

        $this->changePassword()->assertTooManyRequests();
    }

    // ---- Forced change after a temporary password ----

    public function test_a_temporary_password_only_allows_choosing_a_new_one(): void
    {
        $this->staff->forceFill(['must_change_password' => true])->save();
        $this->actingAs($this->staff);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        foreach (['/api/vehicles', '/api/customers', '/api/rentals', '/api/settings/agency'] as $url) {
            $this->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('code', 'password_change_required')
                ->assertJsonPath('message', 'Choose your own password first.');
        }

        $this->changePassword()->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->getJson('/api/vehicles')->assertOk();
    }

    public function test_the_temporary_password_can_be_used_to_log_in_and_then_replaced(): void
    {
        $this->staff->forceFill(['must_change_password' => true])->save();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'youssef@atlasdrive.ma', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->postJson('/api/logout')->assertNoContent(); // logging out is always possible
    }

    // ---- My name ----

    public function test_a_user_changes_their_name_but_not_their_email_or_role(): void
    {
        $this->actingAs($this->staff)
            ->putJson('/api/account/profile', ['name' => 'Youssef Alaoui', 'email' => 'hacker@evil.com', 'role' => 'owner'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Youssef Alaoui')
            ->assertJsonPath('data.email', 'youssef@atlasdrive.ma')
            ->assertJsonPath('data.role', 'staff');
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $this->changePassword()->assertUnauthorized();
        $this->putJson('/api/account/profile', ['name' => 'X'])->assertUnauthorized();
    }
}
