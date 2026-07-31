<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\SetupController;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Launch lock. Until HR completes the critical setup domains (org structure, staff,
 * attendance policy, leave types), plain employees are held on a "workspace is being
 * set up" screen so nobody hits a half-configured system. Privileged roles
 * (manager/management/hr) bypass so they can run — and reach — the setup screens.
 */
class EnsureSystemLaunched
{
    private const PRIVILEGED = ['manager', 'management', 'hr'];

    public function handle(Request $request, Closure $next): Response
    {
        // Tenants that have not opted into onboarding enforcement are never gated.
        $tenant = app(CurrentTenant::class)->get();
        if (! $tenant || ! $tenant->onboarding_enforced) {
            return $next($request);
        }

        $role = $request->attributes->get('tenantRole', 'employee');

        if (in_array($role, self::PRIVILEGED, true)) {
            return $next($request);
        }

        if (app(SetupController::class)->criticalDone()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(423, 'Workspace is still being set up.');
        }

        return response()->view('holding', [], 423);
    }
}
