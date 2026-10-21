<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-session login for the Vue back office (Sanctum SPA authentication).
 * The Vue app first calls GET /sanctum/csrf-cookie, then POST /api/login.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        // Sanctum only starts a session for our own front-end domains (SANCTUM_STATEFUL_DOMAINS).
        abort_unless($request->hasSession(), 403, 'Login is only allowed from the Atlas Drive app.');

        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // New session id after login (prevents session fixation).
        $request->session()->regenerate();

        return new UserResource($request->user()->load('agency'));
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
}
