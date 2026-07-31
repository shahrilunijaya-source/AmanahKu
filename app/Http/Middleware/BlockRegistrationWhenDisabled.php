<?php

namespace App\Http\Middleware;

use App\Services\FeatureManager;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the platform-scoped public self-registration switch (platform.registration).
 * When a super-admin turns it off, both the GET form and the POST handler for /register
 * are closed and visitors are bounced to /login. Login and every other route are untouched.
 */
class BlockRegistrationWhenDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store')
            && ! Features::asBool(app(FeatureManager::class)->platformValue('platform.registration'))) {
            return redirect('/login')->with('status', 'Public sign-up is currently closed. Please contact your administrator for an invitation.');
        }

        return $next($request);
    }
}
