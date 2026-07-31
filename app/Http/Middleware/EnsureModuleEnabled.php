<?php

namespace App\Http\Middleware;

use App\Services\FeatureManager;
use App\Support\Features;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every /app/* request — GET screens AND state-changing write routes — by
 * the module that owns the first path segment. When a tenant has the owning
 * module disabled, the route 404s as if it does not exist. This closes the gap
 * where the AppController screen gate only covers the GET render: a member who
 * knows a write-route name could otherwise POST directly to a disabled module.
 * Runs after ResolveTenant so the active tenant is bound.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($tenant) {
            // Path looks like "app/<screen>/..." — the segment after "app" maps to a screen.
            $parts = explode('/', $request->path());
            $segment = ($parts[0] ?? null) === 'app' ? ($parts[1] ?? null) : null;

            if ($segment !== null) {
                $module = Features::moduleForScreen($segment);

                if ($module !== null && ! app(FeatureManager::class)->enabled($tenant, $module)) {
                    abort(404);
                }
            }
        }

        return $next($request);
    }
}
