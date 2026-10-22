<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * "My account": every user manages their own password and name.
 * (The email is changed by the owner only, so nobody can take over an account by changing it.)
 */
class AccountController extends Controller
{
    /**
     * PUT /api/account/password  { current_password, password, password_confirmation }
     * Also used for the forced change after a temporary password. Other devices are logged out.
     */
    public function password(Request $request): UserResource
    {
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [
            'current_password.current_password' => 'Your current password is not correct.',
            'password.different' => 'Choose a password different from the current one.',
        ]);

        $user = $request->user();
        $user->password = $request->input('password');
        $user->must_change_password = false;
        $user->save();

        // Log out every other device (and their "remember me"); this one stays logged in.
        $currentSession = $request->hasSession() ? $request->session()->getId() : null;
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($currentSession, fn ($query) => $query->where('id', '!=', $currentSession))
            ->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new UserResource($user->load('agency'));
    }

    /**
     * PUT /api/account/profile  { name }
     */
    public function profile(Request $request): UserResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return new UserResource($user->load('agency'));
    }
}
