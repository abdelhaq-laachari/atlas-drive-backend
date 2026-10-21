<?php

namespace Tests\Feature\Auth;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pretend every request comes from the Vue app on the same domain,
        // so Sanctum uses the session cookie (like the real browser).
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_the_vue_app_can_get_a_csrf_cookie(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_a_user_can_log_in_and_receives_their_agency(): void
    {
        $agency = Agency::factory()->create(['name' => 'Atlas Drive']);
        $user = User::factory()->owner()->for($agency)->create(['email' => 'owner@atlasdrive.test']);

        $this->postJson('/api/login', [
            'email' => 'owner@atlasdrive.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.agency.name', 'Atlas Drive')
            ->assertJsonPath('data.agency.rules.late_grace_minutes', 60)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_from_an_unknown_website_is_refused(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_blocked_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();
    }

    public function test_a_logged_in_user_can_see_who_they_are(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.agency.id', $user->agency_id);
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_a_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->postJson('/api/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
