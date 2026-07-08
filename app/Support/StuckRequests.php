<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Claim;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use Illuminate\Support\Collection;

/**
 * The two-step requests (leave / claims / overtime) route verification to the requester's
 * immediate superior via employees.reports_to_id. A requester with NO superior set has
 * their submission land in nobody's verify queue — stuck at 'submitted' indefinitely with
 * no notification to anyone (AK-PROC-04). This surfaces those so HR/management can fix the
 * org chart (assign a superior) or reject the request.
 *
 * Tenant scope is automatic on every query (BelongsToTenant).
 */
class StuckRequests
{
    /** @var list<array{model: class-string, label: string, screen: string}> */
    private const TWO_STEP = [
        ['model' => LeaveRequest::class, 'label' => 'Leave', 'screen' => 'leave'],
        ['model' => Claim::class, 'label' => 'Claim', 'screen' => 'claims'],
        ['model' => OvertimeRequest::class, 'label' => 'Overtime', 'screen' => 'overtime'],
    ];

    /**
     * Submitted two-step requests from employees who have no reporting-line superior,
     * newest-stuck first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forCurrentTenant(): Collection
    {
        $out = collect();

        foreach (self::TWO_STEP as $type) {
            $model = $type['model'];

            // Stuck when there is no LIVE verifier: no ACTIVE primary superior AND no active
            // additional (dotted-line) managers. An archived manager counts as no verifier —
            // reportsTo/additionalManagers are filtered to active here so a request routed to a
            // since-offboarded manager surfaces for HR to re-route, not silently dead-end (H4).
            // Archived requesters are themselves excluded — their pending requests are no
            // longer anyone's problem (active() = archived_at IS NULL).
            $rows = $model::where('status', 'submitted')
                ->whereHas('employee', fn ($q) => $q->active()
                    ->whereDoesntHave('reportsTo', fn ($m) => $m->whereNull('archived_at'))
                    ->whereDoesntHave('additionalManagers'))
                ->with('employee:id,name,initials,avatar_color')
                ->get();

            foreach ($rows as $r) {
                $out->push([
                    'type' => $type['label'],
                    'screen' => $type['screen'],
                    'employee' => $r->employee?->name ?? 'Unknown',
                    'employeeId' => $r->employee_id,
                    'since' => $r->created_at,
                    'ageDays' => $r->created_at ? (int) $r->created_at->diffInDays(now()) : null,
                ]);
            }
        }

        return $out->sortByDesc('ageDays')->values();
    }
}
