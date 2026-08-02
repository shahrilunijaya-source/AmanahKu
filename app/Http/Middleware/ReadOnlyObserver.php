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
 *
 * SETUP_ROUTES is the one hole in that wall. A brand-new company has no position bands,
 * departments, staff levels or employment types, so its first HR admin cannot import staff
 * until somebody builds those lookups by hand — the work that made the observer seat
 * useless on day one. These routes write org STRUCTURE and the staff directory, never
 * money, approvals or access: no payroll, no claims, no leave, no role or permission
 * grants, no login provisioning. Every write through this hole IS attributed — the
 * matching carve-out in AuditLog::record() stamps the super-admin's name on it — so the
 * seat stays invisible among the company's PEOPLE while staying accountable in its ledger.
 */
class ReadOnlyObserver
{
    /**
     * Org-setup writes an observer may perform. Deliberately exhaustive rather than a
     * name prefix: `position.assign` and `employees.update` sit next to entries here and
     * must stay closed, so a prefix match would quietly widen the hole.
     *
     * @var list<string>
     */
    private const SETUP_ROUTES = [
        'position.store', 'position.import', 'position.update', 'position.destroy',
        'admin.branches.store', 'admin.branches.update', 'admin.branches.delete',
        'admin.departments.store', 'admin.departments.update', 'admin.departments.delete',
        'admin.staff-levels.store', 'admin.staff-levels.update', 'admin.staff-levels.delete',
        'admin.employment-types.store', 'admin.employment-types.update', 'admin.employment-types.delete',
        'employees.import',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();

        if ($tenant && $user?->isObserverIn($tenant) && ! $request->isMethodSafe() && ! $request->routeIs(...self::SETUP_ROUTES)) {
            if ($request->expectsJson()) {
                abort(403, 'Super-admin observer access is read-only.');
            }

            return response()->view('errors.observer-read-only', ['tenant' => $tenant], 403);
        }

        return $next($request);
    }
}
