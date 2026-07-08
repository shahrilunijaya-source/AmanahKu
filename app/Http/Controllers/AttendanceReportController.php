<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Services\DataScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only attendance analytics for management / HR: punctuality over time
 * (the trend) with click-through drill-down to a single staff member. All
 * aggregation is done in PHP over a tenant-scoped record set to stay
 * DB-agnostic (mirrors AppController::achievementsData).
 *
 * Off-site / early / short-hours signals are read from the record `flags`
 * array written by ClockService; punctuality is on_time vs late of clocked days.
 */
class AttendanceReportController extends Controller
{
    /** Selectable periods → [days back, trend granularity]. */
    private const PERIODS = [
        'week' => 7,
        'month' => 30,
        'quarter' => 90,
    ];

    public function screenData(Request $request): array
    {
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'month';
        $days = self::PERIODS[$period];
        $end = now()->startOfDay();
        $start = $end->copy()->subDays($days - 1);

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

        // Tenant scope is automatic (BelongsToTenant). Pull the window once, aggregate in PHP.
        $records = AttendanceRecord::query()
            ->with(['employee:id,name,initials,avatar_color,department_id,branch_id', 'employee.department:id,name', 'employee.branch:id,name'])
            // Archived staff are excluded from the report so their clock-ins never feed
            // byStaff / KPIs / coverage (which is measured against active headcount at :66).
            ->whereHas('employee', fn ($q) => $q->active())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->when($dept, fn ($q) => $q->whereHas('employee.department', fn ($d) => $d->where('name', $dept)))
            ->orderBy('date')
            ->get()
            ->filter(fn ($r) => $r->employee !== null) // orphan guard
            ->values();

        // Active headcount drives the participation ("coverage") metric.
        $headcount = Employee::active()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->when($dept, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->where('status', '!=', 'resigned')
            ->count();

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
            'kpis' => $this->kpis($records, $headcount),
            'trend' => $this->trend($records, $start, $end, $period),
            'byStaff' => $this->byStaff($records),
            'drill' => $drill,
            'drillRecords' => $drillRecords,
            'drillTrend' => $empId ? $this->trend($drillRecords, $start, $end, $period) : [],
        ];
    }

    /** Period-wide headline numbers. */
    private function kpis(Collection $records, int $headcount): array
    {
        $clocked = $records->whereNotNull('clock_in');
        $onTime = $clocked->where('status', 'on_time')->count();
        $late = $clocked->where('status', 'late')->count();
        $judged = $onTime + $late;

        $offsite = $records->filter(fn ($r) => $this->hasAnyFlag($r, ['out_of_radius_in', 'out_of_radius_out']))->count();
        $early = $records->filter(fn ($r) => $this->hasFlag($r, 'early_out'))->count();
        $short = $records->filter(fn ($r) => $this->hasFlag($r, 'short_hours'))->count();

        $worked = $clocked->whereNotNull('worked_minutes')->where('worked_minutes', '>', 0);
        $avgMin = $worked->isEmpty() ? 0 : (int) round($worked->avg('worked_minutes'));

        $reported = $records->pluck('employee_id')->unique()->count();

        return [
            'punctuality' => $judged ? (int) round($onTime / $judged * 100) : 0,
            'onTime' => $onTime,
            'late' => $late,
            'offsite' => $offsite,
            'early' => $early,
            'short' => $short,
            'avgHours' => $this->hm($avgMin),
            'clockedDays' => $clocked->count(),
            'coverage' => $headcount ? (int) round($reported / $headcount * 100) : 0,
            'reported' => $reported,
            'headcount' => $headcount,
        ];
    }

    /**
     * Punctuality over time. Daily buckets for week/month; weekly buckets for
     * the quarter so the bar count stays readable. Each bucket carries on-time /
     * late counts and a punctuality %.
     *
     * @return list<array{label:string,sub:string,onTime:int,late:int,total:int,pct:int,weekend:bool}>
     */
    private function trend(Collection $records, Carbon $start, Carbon $end, string $period): array
    {
        $weekly = $period === 'quarter';
        $byDate = $records->whereNotNull('clock_in')->groupBy(fn ($r) => Carbon::parse($r->date)->toDateString());

        $buckets = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if ($weekly) {
                $bucketStart = $cursor->copy();
                $bucketEnd = $cursor->copy()->addDays(6)->min($end);
                $onTime = $late = 0;
                $d = $bucketStart->copy();
                while ($d->lte($bucketEnd)) {
                    [$o, $l] = $this->dayCounts($byDate, $d);
                    $onTime += $o;
                    $late += $l;
                    $d->addDay();
                }
                $buckets[] = $this->bucket($bucketStart->format('j M'), 'wk', $onTime, $late, false);
                $cursor->addDays(7);
            } else {
                [$onTime, $late] = $this->dayCounts($byDate, $cursor);
                $buckets[] = $this->bucket($cursor->format('j'), $cursor->isoFormat('dd'), $onTime, $late, $cursor->isWeekend());
                $cursor->addDay();
            }
        }

        return $buckets;
    }

    /** @return array{0:int,1:int} on-time, late counts for one day. */
    private function dayCounts(Collection $byDate, Carbon $day): array
    {
        $rows = $byDate->get($day->toDateString());
        if (! $rows) {
            return [0, 0];
        }

        return [$rows->where('status', 'on_time')->count(), $rows->where('status', 'late')->count()];
    }

    private function bucket(string $label, string $sub, int $onTime, int $late, bool $weekend): array
    {
        $total = $onTime + $late;

        return [
            'label' => $label,
            'sub' => $sub,
            'onTime' => $onTime,
            'late' => $late,
            'total' => $total,
            'pct' => $total ? (int) round($onTime / $total * 100) : 0,
            'weekend' => $weekend,
        ];
    }

    /**
     * Per-employee roll-up, ordered worst-punctuality first so problem cases
     * surface at the top.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function byStaff(Collection $records): Collection
    {
        return $records->groupBy('employee_id')->map(function (Collection $rows) {
            $emp = $rows->first()->employee;
            $clocked = $rows->whereNotNull('clock_in');
            $onTime = $clocked->where('status', 'on_time')->count();
            $late = $clocked->where('status', 'late')->count();
            $judged = $onTime + $late;
            $worked = $clocked->whereNotNull('worked_minutes')->where('worked_minutes', '>', 0);
            $offsite = $rows->filter(fn ($r) => $this->hasAnyFlag($r, ['out_of_radius_in', 'out_of_radius_out']))->count();

            return [
                'id' => $emp->id,
                'name' => $emp->name,
                'initials' => $emp->initials,
                'color' => $emp->avatar_color,
                'dept' => $emp->department?->name,
                'days' => $clocked->count(),
                'onTime' => $onTime,
                'late' => $late,
                'offsite' => $offsite,
                'avgHours' => $this->hm($worked->isEmpty() ? 0 : (int) round($worked->avg('worked_minutes'))),
                'punctuality' => $judged ? (int) round($onTime / $judged * 100) : 0,
            ];
        })->sortBy([['punctuality', 'asc'], ['late', 'desc']])->values();
    }

    private function hasFlag(AttendanceRecord $r, string $flag): bool
    {
        return in_array($flag, $r->flags ?? [], true);
    }

    /** @param list<string> $flags */
    private function hasAnyFlag(AttendanceRecord $r, array $flags): bool
    {
        return count(array_intersect($flags, $r->flags ?? [])) > 0;
    }

    /** Minutes → "7h 45m" / "7h" / "—". */
    private function hm(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m ? "{$h}h {$m}m" : "{$h}h";
    }
}
