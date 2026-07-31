<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Support\WorkforceInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Apply" handler for the AI Workforce Intelligence (workload) screen recommendations.
 *
 * Every recommendation resolves to an in-app nudge (App\Models\AppNotification) — never an
 * automatic data mutation. Applying "rebalance" does NOT move anyone's tasks; it pings the
 * overloaded staff and their manager so a human decides. This keeps a one-click action from
 * silently rewriting real assignments. Privileged roles only (manager / management / HR;
 * director inherits management via hasTenantRole).
 */
class WorkforceController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    public function apply(Request $request, WorkforceInsights $insights): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $type = $request->validate([
            'type' => ['required', 'in:rebalance,overdue,timesheet'],
        ])['type'];

        $sent = match ($type) {
            'timesheet' => $this->remindTimesheets($insights),
            'overdue' => $this->escalateOverdue($insights),
            'rebalance' => $this->nudgeRebalance($insights),
        };

        return back()->with('ok', $sent === 0
            ? 'Nothing to send — that item is already clear.'
            : 'Nudge sent to '.$sent.' '.Str::plural('person', $sent).'.');
    }

    /** Ping every staff member who has not finished this week's timesheet. */
    private function remindTimesheets(WorkforceInsights $insights): int
    {
        $userIds = $insights->pendingTimesheets()
            ->pluck('user_id')->filter()->unique()->values();

        AppNotification::sendMany(
            $userIds,
            'Timesheet reminder',
            "Please complete this week's timesheet before Friday 5:00pm.",
            url('/app/timesheet'),
        );

        return $userIds->count();
    }

    /** Ping the manager of every staff member holding an overdue item. */
    private function escalateOverdue(WorkforceInsights $insights): int
    {
        $userIds = $insights->overdueItems()
            ->map(fn ($item) => $item->employee?->reportsTo?->user_id)
            ->filter()->unique()->values();

        AppNotification::sendMany(
            $userIds,
            'Overdue work on your team',
            'One or more assignments are past their due date and need attention.',
            url('/app/board'),
        );

        return $userIds->count();
    }

    /** Ping each overloaded person and their manager to review and redistribute load. */
    private function nudgeRebalance(WorkforceInsights $insights): int
    {
        $userIds = collect();

        foreach ($insights->overloaded() as $employee) {
            $userIds->push($employee->user_id);
            $userIds->push($employee->reportsTo?->user_id);
        }

        $userIds = $userIds->filter()->unique()->values();

        AppNotification::sendMany(
            $userIds,
            'Workload review',
            'Your load is flagged as overloaded this week — your manager may redistribute some work.',
            url('/app/workload'),
        );

        return $userIds->count();
    }
}
