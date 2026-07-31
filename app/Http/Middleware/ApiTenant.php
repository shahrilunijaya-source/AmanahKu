<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates the tenant a Sanctum API token is bound to, for /api/v1 requests.
 *
 * Runs after auth:sanctum has resolved the user from the bearer token. Reads the
 * tenant_id stored on the token, verifies the user is still a member of that tenant,
 * and binds it via CurrentTenant so the BelongsToTenant global scope isolates every
 * subsequent query. Also exposes the user's tenant role (employee|manager|management|hr)
 * and employee record on the request attributes, mirroring web ResolveTenant, so the
 * API controllers enforce the same role rules.
 *
 * Any failure (no token, no tenant binding, membership revoked) is a 401 — the request
 * is unauthenticated for that tenant rather than merely forbidden.
 */
class ApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // currentAccessToken() is the PersonalAccessToken model for bearer auth.
        $tenantId = $token?->tenant_id ?? null;

        if (! $user || ! $tenantId) {
            return $this->unauthenticated();
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant || ! $user->tenants->contains('id', $tenant->id)) {
            return $this->unauthenticated();
        }

        app(CurrentTenant::class)->set($tenant);

        // An archived staff record must not act through a lingering API token — the API
        // equivalent of EnsureNotArchived on the web stack. Treated as revoked membership (401).
        $employee = $user->employeeFor($tenant);
        if ($employee && $employee->isArchived()) {
            return $this->unauthenticated();
        }

        $request->attributes->set('tenantRole', $user->roleIn($tenant));
        $request->attributes->set('employee', $employee);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['data' => null, 'error' => 'Unauthenticated.'], 401);
    }
}
