<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the super-admin observer seat to read-only. A super-admin may open any company
 * workspace without holding a membership there (see User::canAccessTenant) so support and
 * development can see exactly what a customer sees. They must not be able to CHANGE what
 * the customer sees: an invisible actor that can write is an unattributable edit, which is
 * the one thing an HR audit trail cannot survive.
 *
 * Runs after ResolveTenant (the tenant must be bound), across the whole /app/* group, so
 * every write path is closed by one gate rather than by a check per controller. Safe
 * methods pass; everything else is a hard 403. A super-admin who genuinely belongs to the
 * company is not an observer and is untouched by this.
 *
 * GET routes that record an audit entry (the payroll exports) are not blocked here — the
 * download is a read. The audit entry itself is dropped by AuditLog::record().
 */
class ReadOnlyObserver
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        if ($tenant && $user?->isObserverIn($tenant) && ! $request->isMethodSafe()) {
            if ($request->expectsJson()) {
                abort(403, 'Super-admin observer access is read-only.');
            }

            return response()->view('errors.observer-read-only', ['tenant' => $tenant], 403);
        }

        return $next($request);
    }
}
