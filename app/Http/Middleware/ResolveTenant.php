<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant from the session, verifies the user is a member,
 * and binds it for the request (used by the BelongsToTenant global scope).
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = session('current_tenant');
        $user = $request->user();

        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        // No active tenant, or the user is not a member of it → back to selection.
        if (! $tenant || ! $user || ! $user->tenants->contains('id', $tenant->id)) {
            return redirect()->route('tenant.select');
        }

        app(CurrentTenant::class)->set($tenant);

        // The signed-in user's role, data scope + employee record within this tenant.
        $request->attributes->set('tenantRole', $user->roleIn($tenant));
        $request->attributes->set('tenantScope', $user->dataScopeIn($tenant));
        $request->attributes->set('employee', $user->employeeFor($tenant));

        view()->share('currentTenant', $tenant);

        return $next($request);
    }
}
