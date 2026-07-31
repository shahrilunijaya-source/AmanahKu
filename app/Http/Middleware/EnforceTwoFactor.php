<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\FeatureManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a tenant's two-factor policy (security.2fa). When the active workspace
 * requires 2FA and the signed-in user has not finished enrolling (no confirmed
 * authenticator), every route funnels to the security screen so they can set it up.
 *
 * Runs after ForcePasswordChange in the web group. A user with no active tenant is
 * never trapped — there is no policy to enforce until they pick a workspace.
 */
class EnforceTwoFactor
{
    /** Route names reachable while a user still owes a 2FA enrolment. */
    private const ALLOWED = [
        'two-factor.enable', 'two-factor.disable', 'two-factor.confirm',
        'two-factor.qr-code', 'two-factor.secret-key',
        'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
        'logout', 'password.change', 'password.change.update', 'tenant.select',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $tenant = $this->activeTenant();

        // No user or no active workspace → nothing to enforce; never trap them.
        if (! $user || ! $tenant) {
            return $next($request);
        }

        $enrolled = $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
        $required = app(FeatureManager::class)->value($tenant, 'security.2fa') === 'required';

        if ($required && ! $enrolled && ! $this->onAllowlist($request)) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication is required for this workspace.');
            }

            return redirect()->route('app.screen', 'security')
                ->with('ok', 'Your workspace requires two-factor authentication. Enable it to continue.');
        }

        return $next($request);
    }

    /** The security screen GET + the 2FA/escape routes a non-enrolled user may still reach. */
    private function onAllowlist(Request $request): bool
    {
        if ($request->routeIs(self::ALLOWED)) {
            return true;
        }

        // The security screen itself (GET /app/security) — that is where they enrol.
        return $request->routeIs('app.screen') && $request->route('screen') === 'security';
    }

    private function activeTenant(): ?Tenant
    {
        $id = session('current_tenant');

        return $id ? Tenant::find($id) : null;
    }
}
