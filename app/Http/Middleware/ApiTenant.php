<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates the tenant a Sanctum API token is bound to, for /api/v1 requests.
 *
 * Two kinds of caller arrive here. A person-token resolves to a User, and keeps the
 * membership, archived and role checks the web stack applies. An app key resolves to
 * an ApiClient, which has no role and no employee record — its scope list is the whole
 * of its authorization. Either way the token's tenant_id activates exactly one tenant,
 * so the BelongsToTenant global scope isolates every subsequent query.
 *
 * Any failure (no token, no tenant binding, membership revoked) is a 401 — the request
 * is unauthenticated for that tenant rather than merely forbidden.
 */
class ApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenable = $request->user();
        $token = $tokenable?->currentAccessToken();
        $tenantId = $token?->tenant_id ?? null;

        if (! $tokenable || ! $token || ! $tenantId) {
            return $this->unauthenticated();
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return $this->unauthenticated();
        }

        // Read once here so the controllers never have to ask what kind of caller this
        // is. A person-token carries ['*'], which every scope check treats as "all".
        $request->attributes->set('tokenAbilities', array_values((array) ($token->abilities ?? [])));

        // Machine caller: an app, not a person. Branch on the string column, not on
        // instanceof — see the plan's note about static analysis.
        if ($token->tokenable_type === ApiClient::class) {
            $client = ApiClient::find($token->tokenable_id);

            // The client row and the token must agree on the tenant. Without this, moving
            // a client to another company leaves its old keys reading the old company.
            if (! $client || $client->tenant_id !== $tenant->id) {
                return $this->unauthenticated();
            }

            app(CurrentTenant::class)->set($tenant);
            $request->attributes->set('apiClient', $client);

            return $next($request);
        }

        // Person caller — unchanged from here down.
        if (! $tokenable->tenants->contains('id', $tenant->id)) {
            return $this->unauthenticated();
        }

        app(CurrentTenant::class)->set($tenant);

        // An archived staff record must not act through a lingering API token — the API
        // equivalent of EnsureNotArchived on the web stack. Treated as revoked membership (401).
        $employee = $tokenable->employeeFor($tenant);
        if ($employee && $employee->isArchived()) {
            return $this->unauthenticated();
        }

        $request->attributes->set('tenantRole', $tokenable->roleIn($tenant));
        $request->attributes->set('employee', $employee);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['data' => null, 'error' => 'Unauthenticated.'], 401);
    }
}
