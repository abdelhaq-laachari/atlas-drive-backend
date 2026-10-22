<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Cookie-session login for the Vue back office (Sanctum SPA authentication).
 * The Vue app first calls GET /sanctum/csrf-cookie, then POST /api/login.
 * With two-step verification on: POST /api/login answers { "two_factor": true }, then
 * POST /api/login/two-factor with the 6-digit code (or a recovery code) finishes the login.
 */
class AuthController extends Controller
{
    /**
     * How long step 1 (right password) waits for step 2 (the code).
     */
    private const TWO_FACTOR_MINUTES = 5;

    public function login(LoginRequest $request): JsonResponse|UserResource
    {
        // Sanctum only starts a session for our own front-end domains (SANCTUM_STATEFUL_DOMAINS).
        abort_unless($request->hasSession(), 403, 'Login is only allowed from the Atlas Drive app.');

        // Always the session ("web") guard, never whatever guard is the default at that moment
        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $request->input('email')]);

        if (! $user || ! $provider->validateCredentials($user, ['password' => $request->input('password')])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Right password, but the owner deactivated this account
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is deactivated. Contact your agency owner.']);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            // Step 1 passed: remember it in the session only (the browser never sees the user id)
            $request->session()->put('two_factor_login', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(self::TWO_FACTOR_MINUTES)->getTimestamp(),
            ]);

            return response()->json(['two_factor' => true]);
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    /**
     * POST /api/login/two-factor  { code: "123456" } or { recovery_code: "abcde-fghij" }
     */
    public function twoFactor(Request $request): UserResource
    {
        abort_unless($request->hasSession(), 403, 'Login is only allowed from the Atlas Drive app.');

        $request->validate([
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
        ]);

        $pending = $request->session()->get('two_factor_login');
        $user = $pending ? User::find($pending['id']) : null;

        if (! $user || $pending['expires_at'] < now()->getTimestamp() || ! $user->isActive() || ! $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget('two_factor_login');

            throw ValidationException::withMessages(['code' => 'Your login has expired. Enter your email and password again.']);
        }

        if (! $this->codeIsValid($user, $request)) {
            throw ValidationException::withMessages([
                $request->filled('code') ? 'code' : 'recovery_code' => 'The code is not correct.',
            ]);
        }

        $request->session()->forget('two_factor_login');

        return $this->completeLogin($request, $user, (bool) $pending['remember']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('agency'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function completeLogin(Request $request, User $user, bool $remember): UserResource
    {
        Auth::guard('web')->login($user, $remember);

        // New session id after login (prevents session fixation).
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return new UserResource($user->load('agency'));
    }

    /**
     * The 6-digit code from the phone app (each code works once), or one of the recovery codes
     * (each recovery code works once, then it is replaced).
     */
    private function codeIsValid(User $user, Request $request): bool
    {
        if ($request->filled('code')) {
            $code = (string) $request->input('code');

            // Our own "used once" lock: Fortify's check lets a code from the next 30-second period
            // be used twice, so every code that logged someone in is refused for 2 minutes.
            $usedKey = 'two_factor_used:'.$user->id.':'.hash('sha256', $code);
            if (Cache::has($usedKey)) {
                return false;
            }

            $valid = app(TwoFactorAuthenticationProvider::class)->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $code,
            );

            if ($valid) {
                Cache::put($usedKey, true, now()->addMinutes(2));
            }

            return $valid;
        }

        $recoveryCode = collect($user->recoveryCodes())
            ->first(fn (string $code) => hash_equals($code, (string) $request->input('recovery_code')));

        if (! $recoveryCode) {
            return false;
        }

        $user->replaceRecoveryCode($recoveryCode);

        return true;
    }
}
