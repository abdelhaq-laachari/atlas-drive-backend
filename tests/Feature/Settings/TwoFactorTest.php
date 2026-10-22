<?php

namespace Tests\Feature\Settings;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['email' => 'owner@atlasdrive.ma']);
        $this->staff = User::factory()->for($this->owner->agency)->create(['email' => 'youssef@atlasdrive.ma']);
    }

    /**
     * The 6-digit code the phone app shows right now.
     */
    private function phoneCode(User $user): string
    {
        return (new Google2FA)->getCurrentOtp(Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret));
    }

    /**
     * The code the phone will show in the next 30-second period (a used code can't be used again,
     * so right after confirming the setup, the user logs in with the next one).
     */
    private function nextPhoneCode(User $user): string
    {
        $google2fa = new Google2FA;
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
    }

    /**
     * Turn two-step verification on through the API, like the user would. Returns the recovery codes.
     *
     * @return list<string>
     */
    private function turnOn(User $user): array
    {
        $this->actingAs($user)->postJson('/api/account/two-factor', ['current_password' => 'password'])->assertOk();

        $codes = $this->postJson('/api/account/two-factor/confirm', ['code' => $this->phoneCode($user)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->app['auth']->forgetGuards();

        return $codes;
    }

    private function login(string $email, string $password = 'password'): TestResponse
    {
        return $this->withHeader('Origin', 'http://localhost')->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    // ---- Turning it on ----

    public function test_setup_gives_a_qr_code_and_needs_the_password(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/account/two-factor', ['current_password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $response = $this->postJson('/api/account/two-factor', ['current_password' => 'password'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertStringStartsWith('<svg', $response->json('data.qr_svg'));
        $this->assertSame(16, strlen($response->json('data.setup_key')));

        // Not on yet: the phone hasn't proven it works
        $this->getJson('/api/me')->assertJsonPath('data.two_factor_enabled', false);
    }

    public function test_it_is_only_on_after_a_correct_code_from_the_phone(): void
    {
        $this->actingAs($this->staff)->postJson('/api/account/two-factor', ['current_password' => 'password']);

        $this->postJson('/api/account/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $codes = $this->postJson('/api/account/two-factor/confirm', ['code' => $this->phoneCode($this->staff)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $codes);
        $this->getJson('/api/me')->assertJsonPath('data.two_factor_enabled', true);
        $this->assertSame('two_factor_enabled', ActivityLog::where('subject_type', 'user')->latest('id')->first()->action);
    }

    public function test_the_secret_and_recovery_codes_never_leak(): void
    {
        $codes = $this->turnOn($this->staff);
        $secret = Fortify::currentEncrypter()->decrypt($this->staff->fresh()->two_factor_secret);

        $everything = json_encode([
            $this->actingAs($this->staff)->getJson('/api/me')->json(),
            $this->actingAs($this->owner)->getJson('/api/settings/users')->json(),
            ActivityLog::all()->pluck('diff'),
        ]);

        $this->assertStringNotContainsString($secret, $everything);
        $this->assertStringNotContainsString($codes[0], $everything);
        $this->assertStringNotContainsString('two_factor_secret', $everything);
        // Stored encrypted in the database
        $this->assertStringNotContainsString($secret, $this->staff->fresh()->getRawOriginal('two_factor_secret'));
    }

    // ---- Logging in with it ----

    public function test_login_needs_the_password_then_the_phone_code(): void
    {
        $this->turnOn($this->staff);

        $this->login('youssef@atlasdrive.ma')
            ->assertOk()
            ->assertExactJson(['two_factor' => true]);

        $this->getJson('/api/me')->assertUnauthorized(); // not logged in yet

        $this->postJson('/api/login/two-factor', ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'The code is not correct.']);

        $this->postJson('/api/login/two-factor', ['code' => $this->nextPhoneCode($this->staff)])
            ->assertOk()
            ->assertJsonPath('data.email', 'youssef@atlasdrive.ma');

        $this->getJson('/api/me')->assertOk();
    }

    public function test_a_phone_code_works_only_once(): void
    {
        $this->turnOn($this->staff);
        $code = $this->nextPhoneCode($this->staff);

        $this->login('youssef@atlasdrive.ma');
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertOk();
        $this->postJson('/api/logout');

        $this->login('youssef@atlasdrive.ma');
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertUnprocessable();
    }

    public function test_a_recovery_code_works_once_when_the_phone_is_lost(): void
    {
        $codes = $this->turnOn($this->staff);

        $this->login('youssef@atlasdrive.ma');
        $this->postJson('/api/login/two-factor', ['recovery_code' => $codes[0]])->assertOk();
        $this->postJson('/api/logout');

        $this->login('youssef@atlasdrive.ma');
        $this->postJson('/api/login/two-factor', ['recovery_code' => $codes[0]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recovery_code' => 'The code is not correct.']);
        $this->assertNotContains($codes[0], $this->staff->fresh()->recoveryCodes());
    }

    public function test_the_code_step_expires_after_5_minutes(): void
    {
        $this->turnOn($this->staff);
        $this->login('youssef@atlasdrive.ma');

        $this->travel(6)->minutes();

        $this->postJson('/api/login/two-factor', ['code' => $this->phoneCode($this->staff)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Your login has expired. Enter your email and password again.']);
    }

    public function test_the_code_step_cannot_be_used_without_the_password_step(): void
    {
        $this->turnOn($this->staff);

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login/two-factor', ['code' => $this->phoneCode($this->staff)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_guessing_codes_is_slowed_down(): void
    {
        $this->turnOn($this->staff);
        $this->login('youssef@atlasdrive.ma');

        foreach (range(1, 5) as $try) {
            $this->postJson('/api/login/two-factor', ['code' => str_pad((string) $try, 6, '0')])->assertUnprocessable();
        }

        $this->postJson('/api/login/two-factor', ['code' => $this->nextPhoneCode($this->staff)])->assertTooManyRequests();
    }

    public function test_a_deactivated_account_cannot_finish_the_code_step(): void
    {
        $this->turnOn($this->staff);
        $this->login('youssef@atlasdrive.ma');

        $this->staff->forceFill(['deactivated_at' => now()])->save();

        $this->postJson('/api/login/two-factor', ['code' => $this->nextPhoneCode($this->staff)])->assertUnprocessable();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    // ---- New recovery codes, turning it off ----

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $old = $this->turnOn($this->staff);

        $this->actingAs($this->staff, 'web')
            ->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'wrong'])
            ->assertUnprocessable();

        $new = $this->postJson('/api/account/two-factor/recovery-codes', ['current_password' => 'password'])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $new);
        $this->assertEmpty(array_intersect($old, $new));
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $this->turnOn($this->staff);

        $this->actingAs($this->staff, 'web')->deleteJson('/api/account/two-factor', ['current_password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/account/two-factor', ['current_password' => 'password'])->assertNoContent();

        $this->assertSame('two_factor_disabled', ActivityLog::where('subject_type', 'user')->latest('id')->first()->action);
        $this->app['auth']->forgetGuards();
        $this->login('youssef@atlasdrive.ma')->assertOk()->assertJsonPath('data.email', 'youssef@atlasdrive.ma');
    }

    // ---- The owner helps a staff member who lost everything ----

    public function test_the_owner_can_reset_a_staff_members_two_step_verification(): void
    {
        $this->turnOn($this->staff);

        $this->actingAs($this->staff)->postJson("/api/settings/users/{$this->staff->id}/reset-two-factor")->assertForbidden();

        $this->actingAs($this->owner)
            ->postJson("/api/settings/users/{$this->staff->id}/reset-two-factor")
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        $log = ActivityLog::where('subject_type', 'user')->latest('id')->first();
        $this->assertSame('two_factor_disabled', $log->action);
        $this->assertSame($this->owner->id, $log->user_id);
    }

    public function test_the_owner_cannot_reset_another_agency_or_their_own(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->owner);
        $this->postJson("/api/settings/users/{$stranger->id}/reset-two-factor")->assertNotFound();
        $this->postJson("/api/settings/users/{$this->owner->id}/reset-two-factor")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'Manage your own two-step verification from "My account".']);
    }
}
