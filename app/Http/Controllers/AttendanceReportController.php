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
use Illuminate\View\View;

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

    public function canSeeLocation(Request $request): bool
    {
        return (bool) $request->user()?->isSuperAdmin()
            || $this->hasTenantRole($request, self::LOCATION_ROLES);
    }

    public function canReversePunch(Request $request): bool
    {
        return (bool) $request->user()?->isSuperAdmin()
            || $this->hasTenantRole($request, self::REVERSE_ROLES);
    }

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

        $canSeeLocation = $this->canSeeLocation($request);

        // Coordinates never reach a viewer who is not allowed to see them, rather than
        // being rendered and hidden. Seeing where a colleague physically stood is a step
        // beyond reading that they were off-site.
        if (! $canSeeLocation) {
            $scoped = $scoped->map(fn (array $row) => ['points' => [], 'hasPoint' => false, 'site' => null] + $row);
        }

        $rows = $this->sort(LedgerTotals::applyLens($scoped, $lens), $sort);

        return [
            'gran' => $period->gran,
            'from' => $from,
            'to' => $to,
            'label' => ['en' => $period->label('en'), 'ms' => $period->label('ms')],
            'rangeLabel' => ['en' => $period->rangeLabel('en'), 'ms' => $period->rangeLabel('ms')],
            'captionKey' => $period->captionKey(),
            'stepLabels' => $this->stepLabels($today),
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

            'person' => $this->person($request, $period, $visibleIds),
            'canReversePunch' => $this->canReversePunch($request),
            'canSeeLocation' => $canSeeLocation,
        ];
    }

    /**
     * Every period label the phone's filter sheet can step to, keyed by granularity
     * and offset.
     *
     * The sheet stages a choice instead of navigating on every tap, so the label has
     * to move before the page does. Rather than reimplement the date maths in Alpine
     * — where it would drift from ReportPeriod and speak only one language — the
     * server hands over the labels it would have rendered anyway. A year back is
     * plenty of reach for a screen whose job is this month's payroll.
     *
     * @return array<string, array<int, array{en: string, ms: string}>>
     */
    private function stepLabels(CarbonImmutable $today): array
    {
        $labels = [];

        foreach (['day', 'week', 'month'] as $gran) {
            for ($offset = 0; $offset >= -12; $offset--) {
                $at = ReportPeriod::fromRequest(['gran' => $gran, 'offset' => $offset], $today);
                $labels[$gran][$offset] = ['en' => $at->label('en'), 'ms' => $at->label('ms')];
            }
        }

        return $labels;
    }

    /**
     * The ledger's own body — controls, chips, totals and rows — as a fragment.
     *
     * A filter change alters nothing outside it, yet re-rendering the screen shipped
     * the sidebar, header and app shell along with it: 220KB and a rebuild of the whole
     * page to swap nine table rows. The screen still renders this inline, so a direct
     * hit, a reload and a JavaScript-free browser are unaffected.
     */
    public function body(Request $request): View
    {
        abort_unless(
            (bool) $request->user()?->isSuperAdmin()
                || Permissions::canSeeAll(
                    $request->attributes->get('employee'),
                    (string) $request->attributes->get('tenantRole'),
                ),
            403
        );

        return view('partials.attendance-report.ledger-body', $this->screenData($request));
    }

    /**
     * The person drawer on its own, as an HTML fragment.
     *
     * Opening a drawer used to mean re-rendering the whole screen: 435 rows and ~1400
     * Alpine bindings rebuilt to show one person's fifteen days, which took the best
     * part of a second. The table behind the drawer never changes, so it is left alone
     * and only this comes over the wire.
     *
     * The screen still renders the drawer server-side for a direct hit on ?emp=, so the
     * deep link, the Fix button and a page reload all keep working without JavaScript.
     */
    public function drawer(Request $request, int $employee): View
    {
        abort_unless(
            (bool) $request->user()?->isSuperAdmin()
                || Permissions::canSeeAll(
                    $request->attributes->get('employee'),
                    (string) $request->attributes->get('tenantRole'),
                ),
            403
        );

        $period = ReportPeriod::fromRequest($request->query(), CarbonImmutable::now()->startOfDay());
        $visibleIds = app(DataScope::class)->visibleEmployeeIds(
            $request->attributes->get('tenantScope', 'company'),
            $request->attributes->get('employee'),
        );

        $person = $this->personDetail($request, $employee, $period, $visibleIds);
        abort_if($person === null, 404);

        return view('partials.attendance-report.person-drawer', [
            'person' => $person,
            'label' => ['en' => $period->label('en'), 'ms' => $period->label('ms')],
            'canReversePunch' => $this->canReversePunch($request),
            'canSeeLocation' => $this->canSeeLocation($request),
        ]);
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

        // Only a past WEEK needs naming: its label is a bare "10 – 14 Aug", which does
        // not say week anywhere. A past month already reads "June 2026" and a past day
        // "Thu, 20 Aug", so prefixing those produced captions like "Week June 2026".
        return $key === 'weekPast'
            ? ['en' => 'Week '.$period->label('en'), 'ms' => 'Minggu '.$period->label('ms')]
            : ['en' => $period->label('en'), 'ms' => $period->label('ms')];
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
     * @param  list<int>|null  $visibleIds
     * @return array<string, mixed>|null
     */
    private function person(Request $request, ReportPeriod $period, ?array $visibleIds): ?array
    {
        if (! $request->filled('emp')) {
            return null;
        }

        return $this->personDetail($request, (int) $request->query('emp'), $period, $visibleIds);
    }

    /**
     * One person's period, built from scratch rather than sliced out of the screen's
     * rows. That is what lets the drawer be served on its own: opening it used to
     * re-render all 435 rows behind it, and Alpine then re-initialised every binding
     * on rows that had not changed.
     *
     * Deliberately NOT filtered by department or name search. Those narrow the table;
     * they have no business emptying a person's own month when you open them. The data
     * scope still applies — that one is a permission, not a filter.
     *
     * @param  list<int>|null  $visibleIds
     * @return array<string, mixed>|null
     */
    public function personDetail(Request $request, int $id, ReportPeriod $period, ?array $visibleIds): ?array
    {
        if ($visibleIds !== null && ! in_array($id, $visibleIds, true)) {
            return null;
        }

        // active() so a crafted ?emp=<archived> can't open an archived person's detail.
        // Route-model binding resolves across every tenant (SubstituteBindings runs before
        // ResolveTenant), so this lookup goes through the tenant-scoped model instead.
        $employee = Employee::active()->with('department:id,name')->find($id);
        if ($employee === null) {
            return null;
        }

        $from = $period->from->toDateString();
        $dayAfter = $period->to->addDay()->toDateString();

        $records = AttendanceRecord::query()
            ->where('employee_id', $id)
            ->where('date', '>=', $from)
            ->where('date', '<', $dayAfter)
            ->orderBy('date')
            ->get();

        $leaveRequests = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('employee_id', $id)
            ->where('date_from', '<', $dayAfter)
            ->where('date_to', '>=', $from)
            ->with('leaveType:id,name')
            ->get();

        $workingDays = $period->workingDays(
            $records->map(fn ($r) => $r->date->toDateString())->unique()->values()->all()
        );

        $scoped = app(LedgerBuilder::class)->build(
            collect([$employee]), $records, $leaveRequests, $workingDays,
            CarbonImmutable::now()->startOfDay(),
        );

        if (! $this->canSeeLocation($request)) {
            $scoped = $scoped->map(fn (array $row) => ['points' => [], 'hasPoint' => false, 'site' => null] + $row);
        }

        $byDate = $records->keyBy(fn ($r) => $r->date->toDateString());

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
