<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Attendance\LedgerBuilder;
use App\Attendance\LedgerTotals;
use App\Attendance\ReportPeriod;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\DataScope;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only attendance ledger for management / HR: one row per active employee
 * per working day, whether they clocked or not.
 */
class AttendanceReportController extends Controller
{
    /**
     * Reversing a punch is a step above the rest of this (already management/HR-gated)
     * report: 'management' is left out on purpose — only HR, a director (board tier), or
     * a super-admin observer may undo one. Mirrors AttendanceAdminController::REVERSE_ROLES,
     * which owns the actual reversePunch() action.
     */
    private const REVERSE_ROLES = ['hr', 'director'];

    /**
     * Seeing where a colleague physically stood is a step beyond reading that they
     * were off-site, so it does not inherit this screen's own gate. Built from
     * Permissions::OVERSIGHT_ROLES so the two can't drift apart; 'director' is
     * redundant (effectiveRole() maps it to 'management') but named anyway, matching
     * REVERSE_ROLES above.
     */
    private const LOCATION_ROLES = [...Permissions::OVERSIGHT_ROLES, 'director'];

    /** The exception chips above the table. Anything else means "show everything". */
    private const LENSES = ['miss', 'absent', 'short', 'late'];

    /** The caption the totals block wears, keyed by ReportPeriod::captionKey(). */
    private const CAPTIONS = [
        'month' => ['en' => 'Month to date', 'ms' => 'Bulan setakat ini'],
        'week' => ['en' => 'This week', 'ms' => 'Minggu ini'],
        'day' => ['en' => 'Today', 'ms' => 'Hari ini'],
        'custom' => ['en' => 'Selected range', 'ms' => 'Julat dipilih'],
    ];

    /** @return array<string, mixed> */
    public function screenData(Request $request): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $period = ReportPeriod::fromRequest($request->query(), $today);

        $dept = $request->query('dept') ?: null;
        $q = trim((string) $request->query('q', ''));
        $sort = $request->query('sort') === 'person' ? 'person' : 'date';
        $lens = in_array($request->query('lens'), self::LENSES, true) ? $request->query('lens') : null;

        // A branch/department-restricted manager only sees their own slice (AK-AUTHZ-01).
        // null = 'company' scope, no limit.
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $self);

        // active() only clears the archive, so 'resigned' still needs excluding by hand.
        $employees = Employee::active()
            ->when($visibleIds !== null, fn ($b) => $b->whereIn('id', $visibleIds))
            ->when($dept, fn ($b) => $b->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))
            ->where('status', '!=', 'resigned')
            ->with(['department:id,name'])
            ->get();

        $employeeIds = $employees->pluck('id')->all();
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        // Half-open upper bound, never `<= $to`: sqlite stores a date cast with a
        // 00:00:00 time part, so '2026-07-14 00:00:00' sorts AFTER '2026-07-14' and a
        // plain BETWEEN silently drops every record on the window's last day. Same
        // reasoning as AttendanceRecord::scopeOnDate().
        $dayAfter = $period->to->addDay()->toDateString();

        // Tenant scope is automatic (BelongsToTenant).
        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('date', '>=', $from)
            ->where('date', '<', $dayAfter)
            ->orderBy('date')
            ->get();

        $leaveRequests = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->where('date_from', '<', $dayAfter)
            ->where('date_to', '>=', $from)
            ->with('leaveType:id,name')
            ->get();

        $workingDays = $period->workingDays(
            $records->map(fn ($r) => $r->date->toDateString())->unique()->values()->all()
        );

        $scoped = app(LedgerBuilder::class)
            ->build($employees, $records, $leaveRequests, $workingDays, $today);

        $canSeeLocation = (bool) $request->user()?->isSuperAdmin()
            || $this->hasTenantRole($request, self::LOCATION_ROLES);

        // Coordinates never reach a viewer who is not allowed to see them, rather than
        // being rendered and hidden. Seeing where a colleague physically stood is a step
        // beyond reading that they were off-site.
        if (! $canSeeLocation) {
            $scoped = $scoped->map(fn (array $row) => ['points' => [], 'hasPoint' => false] + $row);
        }

        $rows = $this->sort(LedgerTotals::applyLens($scoped, $lens), $sort);

        return [
            'gran' => $period->gran,
            'from' => $from,
            'to' => $to,
            'label' => ['en' => $period->label('en'), 'ms' => $period->label('ms')],
            'rangeLabel' => ['en' => $period->rangeLabel('en'), 'ms' => $period->rangeLabel('ms')],
            'captionKey' => $period->captionKey(),
            'canPrev' => $period->canPrev,
            'canNext' => $period->canNext,
            'offset' => $period->offset,
            'workingDays' => $workingDays,

            'dept' => $dept,
            'departments' => Department::orderBy('name')->pluck('name'),
            'q' => $q,
            'sort' => $sort,
            'lens' => $lens,

            'rows' => $rows,
            'counts' => LedgerTotals::counts($scoped),
            'totals' => LedgerTotals::of($scoped) + ['caption' => $this->caption($period)],

            'person' => $this->person($request, $scoped, $records, $visibleIds),
            'canReversePunch' => (bool) $request->user()?->isSuperAdmin()
                || $this->hasTenantRole($request, self::REVERSE_ROLES),
            'canSeeLocation' => $canSeeLocation,
        ];
    }

    /**
     * The caption names the period it actually totals. A past week or day gets its
     * own dates rather than "This week", which would be a lie about which week.
     *
     * @return array{en: string, ms: string}
     */
    private function caption(ReportPeriod $period): array
    {
        $key = $period->captionKey();

        if (isset(self::CAPTIONS[$key])) {
            return self::CAPTIONS[$key];
        }

        return $key === 'dayPast'
            ? ['en' => $period->label('en'), 'ms' => $period->label('ms')]
            : ['en' => 'Week '.$period->label('en'), 'ms' => 'Minggu '.$period->label('ms')];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $rows, string $sort): Collection
    {
        return $rows->sort(function (array $a, array $b) use ($sort): int {
            if ($sort === 'person') {
                return [$a['name'], $a['date']] <=> [$b['name'], $b['date']];
            }

            // Newest first, then alphabetical inside the day.
            return [$b['date'], $a['name']] <=> [$a['date'], $b['name']];
        })->values();
    }

    /**
     * The drawer's payload. Returns null when ?emp= is absent, archived, or outside
     * the viewer's data scope — a crafted id must not open somebody else's month.
     *
     * @param  Collection<int, array<string, mixed>>  $scoped
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  list<int>|null  $visibleIds
     * @return array<string, mixed>|null
     */
    private function person(
        Request $request,
        Collection $scoped,
        Collection $records,
        ?array $visibleIds,
    ): ?array {
        if (! $request->filled('emp')) {
            return null;
        }

        $id = (int) $request->query('emp');
        if ($visibleIds !== null && ! in_array($id, $visibleIds, true)) {
            return null;
        }

        // active() so a crafted ?emp=<archived> can't open an archived person's detail.
        $employee = Employee::active()->with('department:id,name')->find($id);
        if ($employee === null) {
            return null;
        }

        $byDate = $records->where('employee_id', $id)->keyBy(fn ($r) => $r->date->toDateString());

        // The table row is the shape everything else on this screen already agrees on;
        // the drawer just needs the parts a one-line row has no room for.
        $days = $scoped->where('employeeId', $id)
            ->sortByDesc('date')
            ->map(function (array $row) use ($byDate): array {
                $r = $byDate->get($row['date']);

                return $row + [
                    'noteIn' => $r?->clock_in_justification,
                    'noteOut' => $r?->clock_out_justification,
                    'photoIn' => $r?->photo_url,
                    'photoOut' => $r?->clock_out_photo_url,
                ];
            })
            ->values();

        // ?day= deep-links a single day open, so the Fix button on a ledger row and
        // a plain click on the person both land on the same screen.
        $openDay = $request->query('day');
        $openDay = is_string($openDay) && $days->contains('date', $openDay) ? $openDay : null;

        return [
            'id' => $employee->id,
            'name' => $employee->display_name,
            'initials' => $employee->initials,
            'color' => $employee->avatar_color,
            'dept' => $employee->department?->name,
            'days' => $days,
            'openDay' => $openDay,
        ];
    }
}
