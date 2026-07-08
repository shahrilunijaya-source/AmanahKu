<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every tenant route when the active company is suspended or its subscription
 * window has lapsed. Runs after ResolveTenant (so the tenant is bound) and before
 * EnsureModuleEnabled. The user is not logged out — they keep the workspace picker
 * and logout — but no /app/* surface is reachable until the company is reactivated.
 * Only a super-admin can change company status (see SuperAdmin\CompanyController).
 */
class EnsureCompanyIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant && (! $tenant->isActive() || $tenant->subscriptionExpired())) {
            $reason = $tenant->isActive() ? 'expired' : 'suspended';

            if ($request->expectsJson()) {
                abort(403, 'This company workspace is currently '.$reason.'.');
            }

            return response()->view('errors.company-suspended', [
                'tenant' => $tenant,
                'reason' => $reason,
            ], 403);
        }

        return $next($request);
    }
}
