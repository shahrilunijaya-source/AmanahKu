<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the cross-tenant provisioning console. Only a flagged super-admin may
 * reach company-management routes; everyone else gets a hard 403 (not a redirect),
 * so the surface is invisible to ordinary tenant users.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->isSuperAdmin(), 403);

        return $next($request);
    }
}
