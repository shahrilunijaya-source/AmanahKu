<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\DataScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only attendance analytics for management / HR: roster view of active staff.
 */
class AttendanceReportController extends Controller
{
    /** Selectable periods → [days back, trend granularity]. */
    private const PERIODS = [
        'week' => 7,
        'month' => 30,
        'quarter' => 90,
    ];

    /**
     * Reversing a punch from the drill-down is a step above the rest of this (already
     * management/HR-gated) report: 'management' is left out on purpose — only HR, a
     * director (board tier), or a super-admin observer may undo one. Mirrors
     * AttendanceAdminController::REVERSE_ROLES, which owns the actual reversePunch() action.
     */
    private const REVERSE_ROLES = ['hr', 'director'];

    public function screenData(Request $request): array
    {
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'month';
        $daysBack = self::PERIODS[$period];
        $end = now()->startOfDay();
        $start = $end->copy()->subDays($daysBack - 1);

        $dept = $request->query('dept') ?: null;
        $empId = $request->filled('emp') ? (int) $request->query('emp') : null;

        // Data scope: a branch/department-restricted manager only sees their own slice
        // of the company's attendance (AK-AUTHZ-01). null = 'company' scope, no limit.
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $self);

        // A drill-through to a staff member outside the viewer's scope is refused.
        if ($empId !== null && $visibleIds !== null && ! in_array($empId, $visibleIds, true)) {
            $empId = null;
        }

        // Active employee set for the roster.
        $employees = Employee::active()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->when($dept, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->where('status', '!=', 'resigned')
            ->with(['department:id,name'])
            ->get();

        // Tenant scope is automatic (BelongsToTenant).
        $records = AttendanceRecord::query()
            ->with(['employee:id,name,initials,avatar_color,department_id,branch_id', 'employee.department:id,name', 'employee.branch:id,name'])
            ->whereHas('employee', fn ($q) => $q->active())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when($dept, fn ($q) => $q->whereHas('employee.department', fn ($d) => $d->where('name', $dept)))
            ->orderBy('date')
            ->get()
            ->filter(fn ($r) => $r->employee !== null) // orphan guard
            ->values();

        // Approved leave requests for the window.
        $leaveRequests = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('date_from', '<=', $end->toDateString())
            ->where('date_to', '>=', $start->toDateString())
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->get(['employee_id', 'date_from', 'date_to']);

        // Build working-day list: Mon-Fri plus any date in window on which any visible employee has a record.
        $recordDates = $records->pluck('date')
            ->map(fn ($d) => is_string($d) ? $d : Carbon::parse($d)->toDateString())
            ->unique()
            ->toArray();

        $days = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            if ($cursor->isWeekday() || in_array($dateStr, $recordDates, true)) {
                $days[] = $dateStr;
            }
            $cursor->addDay();
        }

        // Determine strip unit and cell labels for day vs week mode
        $stripUnit = $period === 'quarter' ? 'week' : 'day';
        $weekBuckets = [];
        if ($period === 'quarter') {
            foreach ($days as $idx => $dateStr) {
                $wStart = Carbon::parse($dateStr)->startOfWeek(Carbon::MONDAY)->toDateString();
                $weekBuckets[$wStart][] = $idx;
            }
            $cells = array_keys($weekBuckets);
        } else {
            $cells = $days;
        }

        // Map records and leave requests for O(1) lookup
        $recordsMap = [];
        foreach ($records as $r) {
            $recordsMap[$r->employee_id][$r->date->toDateString()] = $r;
        }

        $leaveMap = [];
        foreach ($leaveRequests as $l) {
            $dFrom = $l->date_from->toDateString();
            $dTo = $l->date_to->toDateString();
            $leaveMap[$l->employee_id][] = ['from' => $dFrom, 'to' => $dTo];
        }

        // Build roster rows
        $rosterUnsorted = $employees->map(function (Employee $emp) use ($days, $recordsMap, $leaveMap, $period, $weekBuckets) {
            $strip = '';
            foreach ($days as $dateStr) {
                $r = $recordsMap[$emp->id][$dateStr] ?? null;
                if ($r !== null && $this->hasAnyFlag($r, ['out_of_radius_in', 'out_of_radius_out'])) {
                    $strip .= 'x';
                } elseif ($r !== null && $r->status === 'late') {
                    $strip .= 'l';
                } elseif ($r !== null && $r->clock_in !== null) {
                    $strip .= 'o';
                } else {
                    $isCoveredByLeave = false;
                    if (isset($leaveMap[$emp->id])) {
                        foreach ($leaveMap[$emp->id] as $l) {
                            if ($dateStr >= $l['from'] && $dateStr <= $l['to']) {
                                $isCoveredByLeave = true;
                                break;
                            }
                        }
                    }
                    $strip .= $isCoveredByLeave ? 'v' : '-';
                }
            }

            $onTime = substr_count($strip, 'o');
            $late = substr_count($strip, 'l');
            $offsite = substr_count($strip, 'x');
            $clocked = $onTime + $late + $offsite;
            $leaveDays = substr_count($strip, 'v');

            $denom = $clocked;
            $pct = $denom > 0 ? (int) round(($onTime + $offsite) / $denom * 100) : null;

            $lastSeen = null;
            $gapDays = null;
            $daysCount = count($days);
            for ($i = $daysCount - 1; $i >= 0; $i--) {
                $c = $strip[$i];
                if ($c !== '-' && $c !== 'v') {
                    $lastSeen = $days[$i];
                    $gapDays = ($daysCount - 1) - $i;
                    break;
                }
            }

            $never = ($clocked === 0 && $leaveDays === 0);
            $stopped = ($clocked > 0 && preg_match('/-{5,}$/', $strip) === 1);
            $onLeave = ($leaveDays > 0);

            // Fold daily strip into weekly cells for quarter period
            if ($period === 'quarter') {
                $displayStrip = '';
                foreach ($weekBuckets as $dayIndices) {
                    $wOnTime = 0;
                    $wLate = 0;
                    $wOffsite = 0;
                    $wLeave = 0;
                    foreach ($dayIndices as $idx) {
                        $c = $strip[$idx];
                        if ($c === 'o') {
                            $wOnTime++;
                        } elseif ($c === 'l') {
                            $wLate++;
                        } elseif ($c === 'x') {
                            $wOffsite++;
                        } elseif ($c === 'v') {
                            $wLeave++;
                        }
                    }
                    $wClocked = $wOnTime + $wLate + $wOffsite;
                    if ($wClocked > 0) {
                        $wPct = (int) round(($wOnTime + $wOffsite) / $wClocked * 100);
                        $displayStrip .= $wPct >= 90 ? 'o' : ($wPct >= 75 ? 'l' : 'x');
                    } elseif ($wLeave > 0) {
                        $displayStrip .= 'v';
                    } else {
                        $displayStrip .= '-';
                    }
                }
                $rowStrip = $displayStrip;
            } else {
                $rowStrip = $strip;
            }

            return [
                'id' => $emp->id,
                'name' => $emp->display_name,
                'initials' => $emp->initials,
                'color' => $emp->avatar_color,
                'dept' => $emp->department?->name,
                'strip' => $rowStrip,
                'clocked' => $clocked,
                'onTime' => $onTime,
                'late' => $late,
                'offsite' => $offsite,
                'leaveDays' => $leaveDays,
                'pct' => $pct,
                'lastSeen' => $lastSeen,
                'gapDays' => $gapDays,
                'never' => $never,
                'stopped' => $stopped,
                'onLeave' => $onLeave,
            ];
        });

        // Sort roster
        $roster = $rosterUnsorted->sort(function (array $a, array $b): int {
            // Approved leave is not a problem, so a person whose whole absence is
            // explained by leave sorts below everyone, not above the worst
            // attender. Someone who took two days' leave and worked the rest is an
            // ordinary row and sorts on punctuality like anyone else.
            $tier = fn (array $r): int => $r['never'] ? 0
                : ($r['stopped'] ? 1 : (($r['onLeave'] && $r['clocked'] === 0) ? 3 : 2));

            $tierA = $tier($a);
            $tierB = $tier($b);

            if ($tierA !== $tierB) {
                return $tierA <=> $tierB;
            }

            $pctA = $a['pct'];
            $pctB = $b['pct'];

            if ($pctA !== $pctB) {
                if ($pctA === null) {
                    return 1;
                }
                if ($pctB === null) {
                    return -1;
                }

                return $pctA <=> $pctB;
            }

            if ($a['late'] !== $b['late']) {
                return $b['late'] <=> $a['late'];
            }

            return $a['id'] <=> $b['id'];
        })->values();

        // Totals
        $headcount = $roster->count();
        $reported = $roster->where('clocked', '>', 0)->count();
        $onLeaveCount = $roster->where('onLeave', true)->count();
        $neverCount = $roster->where('never', true)->count();
        $stoppedCount = $roster->where('stopped', true)->count();

        $bucketClocking = $roster->filter(fn ($r) => $r['clocked'] > 0 && ! $r['stopped'])->count();
        $bucketStopped = $roster->filter(fn ($r) => $r['clocked'] > 0 && $r['stopped'])->count();
        $bucketOnLeave = $roster->filter(fn ($r) => $r['clocked'] === 0 && $r['leaveDays'] > 0)->count();
        $bucketNever = $roster->filter(fn ($r) => $r['clocked'] === 0 && $r['leaveDays'] === 0)->count();

        $clockedDaysSum = (int) $roster->sum('clocked');
        $lateDaysSum = (int) $roster->sum('late');
        $totalsPct = $clockedDaysSum > 0 ? (int) round(($clockedDaysSum - $lateDaysSum) / $clockedDaysSum * 100) : 0;

        $totals = [
            'headcount' => $headcount,
            'reported' => $reported,
            'onLeave' => $onLeaveCount,
            'never' => $neverCount,
            'stopped' => $stoppedCount,
            'bucketClocking' => $bucketClocking,
            'bucketStopped' => $bucketStopped,
            'bucketOnLeave' => $bucketOnLeave,
            'bucketNever' => $bucketNever,
            'clockedDays' => $clockedDaysSum,
            'lateDays' => $lateDaysSum,
            'pct' => $totalsPct,
        ];

        $drill = $empId ? $records->firstWhere('employee_id', $empId)?->employee : null;
        if ($empId && ! $drill) {
            // active() so a crafted ?emp=<archived> can't open an archived person's detail.
            $drill = Employee::active()->with(['department:id,name', 'branch:id,name'])->find($empId);
        }
        $drillRecords = $empId
            ? $records->where('employee_id', $empId)->sortByDesc('date')->values()
            : collect();

        return [
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'rangeLabel' => $start->format('j M').' – '.$end->format('j M Y'),
            'dept' => $dept,
            'departments' => Department::orderBy('name')->pluck('name'),
            'days' => $days,
            'stripUnit' => $stripUnit,
            'cells' => $cells,
            'roster' => $roster,
            'totals' => $totals,
            'drill' => $drill,
            'drillRecords' => $drillRecords,
            'canReversePunch' => (bool) $request->user()?->isSuperAdmin() || $this->hasTenantRole($request, self::REVERSE_ROLES),
        ];
    }

    /** @param list<string> $flags */
    private function hasAnyFlag(AttendanceRecord $r, array $flags): bool
    {
        return count(array_intersect($flags, $r->flags ?? [])) > 0;
    }
}
