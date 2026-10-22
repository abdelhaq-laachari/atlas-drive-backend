<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone logged in with a temporary password (given by the owner or the super admin) may only
 * see who they are, log out, or choose their own password. Everything else waits.
 */
class EnsurePasswordIsChanged
{
    /**
     * Still allowed while the password must be changed.
     */
    private const ALLOWED = ['api/me', 'api/logout', 'api/account/password'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->is(self::ALLOWED)) {
            return response()->json([
                'message' => 'Choose your own password first.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
