<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every tenant route for a user whose own staff record in the active company is
 * archived. Archive is a soft removal (EmployeeController::destroy sets archived_at) that
 * deliberately leaves the login + tenant membership intact so historical references still
 * resolve — but an archived person must not be able to ACT. This is the single request-time
 * choke-point that enforces "archived = no live access": with the acting employee bound by
 * ResolveTenant, one archived check here detaches them from clock-ins, board edits, approvals
 * and every other write across /app/*.
 *
 * Runs after ResolveTenant (which binds the `employee` attribute) and company.active, before
 * module/profile gates. Scoped per-tenant, so a user active in one company but archived in
 * another keeps full access to the company where they are active. Mirrors EnsureCompanyIsActive:
 * the user is not logged out — they keep the workspace picker and logout.
 */
class EnsureNotArchived
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->attributes->get('employee');

        if ($employee instanceof Employee && $employee->isArchived()) {
            if ($request->expectsJson()) {
                abort(403, 'Your access to this workspace has been removed.');
            }

            return response()->view('errors.workspace-access-removed', [
                'tenant' => app(CurrentTenant::class)->get(),
            ], 403);
        }

        return $next($request);
    }
}
