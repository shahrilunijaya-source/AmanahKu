<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\DataScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only leave analytics for management / HR: how much leave the company takes,
 * split by type and by person, with unplanned (emergency) leave surfaced as a
 * red-flag signal. Click-through drills into a single staff member.
 *
 * Aggregation is done in PHP over a tenant- and data-scope-limited record set to
 * stay DB-agnostic (mirrors AttendanceReportController).
 */
class LeaveReportController extends Controller
{
    /** Selectable periods → days back. 'ytd' is handled specially (calendar year). */
    private const PERIODS = ['ytd' => 0, '12m' => 365, 'quarter' => 90];

    public function screenData(Request $request): array
    {
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'ytd';
        $end = now()->endOfDay();
        $start = $period === 'ytd'
            ? now()->startOfYear()
            : now()->subDays(self::PERIODS[$period] - 1)->startOfDay();

        $dept = $request->query('dept') ?: null;
        $empId = $request->filled('emp') ? (int) $request->query('emp') : null;

        // Data scope: a branch/department-restricted manager only sees their slice
        // (AK-AUTHZ-01). null = company scope, no limit.
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $self);

        // A drill-through to a staff member outside the viewer's scope is refused.
        if ($empId !== null && $visibleIds !== null && ! in_array($empId, $visibleIds, true)) {
            $empId = null;
        }

        // Approved leave taken in the window. Tenant scope is automatic (BelongsToTenant).
        $taken = LeaveRequest::query()
            ->with(['leaveType:id,name,is_unplanned', 'employee:id,name,initials,avatar_color,department_id', 'employee.department:id,name'])
            ->where('status', 'approved')
            ->whereBetween('date_from', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when($dept, fn ($q) => $q->whereHas('employee.department', fn ($d) => $d->where('name', $dept)))
            ->get()
            ->filter(fn ($r) => $r->employee !== null) // orphan guard
            ->values();

        // Still-pending requests (submitted or verified) across the same scope.
        $pending = LeaveRequest::query()
            ->whereIn('status', ['submitted', 'verified'])
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->count();

        $headcount = Employee::active()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->where('status', '!=', 'resigned')
            ->count();

        // Annual balance per employee — the planned-leave gauge shown in the staff table.
        $annualTypeId = LeaveType::where('name', 'Annual')->value('id');
        $annualBalances = $annualTypeId
            ? LeaveBalance::where('leave_type_id', $annualTypeId)
                ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
                ->pluck('balance', 'employee_id')
            : collect();

        $drill = $empId ? Employee::with('department:id,name')->find($empId) : null;

        return [
            'period' => $period,
            'periods' => array_keys(self::PERIODS),
            'rangeLabel' => $start->format('j M Y').' – '.$end->format('j M Y'),
            'dept' => $dept,
            'departments' => Department::orderBy('name')->pluck('name'),
            'kpis' => $this->kpis($taken, $pending, $headcount),
            'byType' => $this->byType($taken),
            'byStaff' => $this->byStaff($taken, $annualBalances),
            'drill' => $drill,
            'drillRequests' => $empId ? $taken->where('employee_id', $empId)->sortByDesc('date_from')->values() : collect(),
            'drillByType' => $empId ? $this->byType($taken->where('employee_id', $empId)->values()) : collect(),
        ];
    }

    /** Company-wide headline numbers. */
    private function kpis(Collection $taken, int $pending, int $headcount): array
    {
        $totalDays = (float) $taken->sum('days');
        $unplanned = $taken->filter(fn ($r) => $r->leaveType?->is_unplanned);
        $unplannedDays = (float) $unplanned->sum('days');

        return [
            'totalDays' => $totalDays,
            'requests' => $taken->count(),
            'pending' => $pending,
            'unplannedDays' => $unplannedDays,
            'unplannedPct' => $totalDays > 0 ? (int) round($unplannedDays / $totalDays * 100) : 0,
            'unplannedStaff' => $unplanned->pluck('employee_id')->unique()->count(),
            'avgPerHead' => $headcount ? round($totalDays / $headcount, 1) : 0.0,
            'headcount' => $headcount,
            'staffTaken' => $taken->pluck('employee_id')->unique()->count(),
        ];
    }

    /**
     * Days taken per leave type, most days first.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function byType(Collection $taken): Collection
    {
        return $taken->groupBy(fn ($r) => $r->leaveType?->name ?? 'Unknown')->map(function (Collection $rows, string $name) {
            return [
                'name' => $name,
                'days' => (float) $rows->sum('days'),
                'requests' => $rows->count(),
                'unplanned' => (bool) $rows->first()->leaveType?->is_unplanned,
            ];
        })->sortByDesc('days')->values();
    }

    /**
     * Per-employee roll-up, ordered most unplanned leave first so frequent
     * emergency takers surface at the top.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function byStaff(Collection $taken, Collection $annualBalances): Collection
    {
        return $taken->groupBy('employee_id')->map(function (Collection $rows) use ($annualBalances) {
            $emp = $rows->first()->employee;
            $unplanned = $rows->filter(fn ($r) => $r->leaveType?->is_unplanned);
            $unplannedDays = (float) $unplanned->sum('days');
            $totalDays = (float) $rows->sum('days');

            return [
                'id' => $emp->id,
                'name' => $emp->name,
                'initials' => $emp->initials,
                'color' => $emp->avatar_color,
                'dept' => $emp->department?->name,
                'totalDays' => $totalDays,
                'plannedDays' => $totalDays - $unplannedDays,
                'unplannedDays' => $unplannedDays,
                'unplannedCount' => $unplanned->count(),
                'requests' => $rows->count(),
                'annualRemaining' => isset($annualBalances[$emp->id]) ? (float) $annualBalances[$emp->id] : null,
            ];
        })->sortBy([['unplannedDays', 'desc'], ['totalDays', 'desc']])->values();
    }
}
