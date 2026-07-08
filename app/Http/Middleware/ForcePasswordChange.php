<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks an authenticated user out of every application route until they rotate the
 * one-time password they were invited with (I-008). Only the change-password screen
 * and logout are reachable while the flag is set.
 */
class ForcePasswordChange
{
    /** Route names a flagged user may still reach. */
    private const ALLOWED = ['password.change', 'password.change.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->password_change_required && ! $request->routeIs(self::ALLOWED)) {
            if ($request->expectsJson()) {
                abort(423, 'Password change required before continuing.');
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
