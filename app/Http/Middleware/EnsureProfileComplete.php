<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-tier onboarding gate. A plain employee whose ESSENTIAL profile (identity core
 * + contact/emergency) is not yet filled is funnelled into the first-login wizard.
 * Encouraged-but-incomplete data (bank, certificates, personality) never blocks —
 * the dashboard nudge chases that. Only the 'employee' role is gated; privileged
 * roles and users without an employee record in this workspace pass straight through.
 */
class EnsureProfileComplete
{
    /**
     * Route names an incomplete employee may still reach while being gated.
     * Currently this middleware is only attached to the app.screen catch-all (the
     * wizard's own /app/welcome routes are registered separately and never hit it),
     * so this allowlist is defensive: it keeps the wizard + sign-out reachable if the
     * gate is ever applied more broadly (e.g. to the whole tenant route group).
     */
    private const ALLOWED = [
        'welcome.show', 'welcome.personal', 'welcome.bank', 'welcome.certificate',
        'welcome.finish', 'profile-test.submit', 'logout', 'password.change', 'password.change.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Tenants that have not opted into onboarding enforcement are never gated.
        $tenant = app(CurrentTenant::class)->get();
        if (! $tenant || ! $tenant->onboarding_enforced) {
            return $next($request);
        }

        $role = $request->attributes->get('tenantRole', 'employee');
        $employee = $request->attributes->get('employee');

        // Only plain employees with a real record are gated. Privileged roles run
        // setup; users with no employee record have no profile to complete.
        if ($role !== 'employee' || ! $employee instanceof Employee) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        if (app(ProfileCompletion::class)->essentialDone($employee)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'Complete your profile before continuing.');
        }

        return redirect()->route('welcome.show');
    }
}
