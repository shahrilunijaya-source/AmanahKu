<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\CalendarController;
use App\Models\AttendanceRecord;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Services\FeatureManager;
use App\Support\DashboardPrefs;
use App\Support\DashboardWidgets;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Payload builders for the single-grid dashboard — one method per widget on
 * App\Support\DashboardWidgets, plus the entry point AppController::screen()
 * calls for the 'dash' screen.
 *
 * Only widgets the viewer can actually see AND has not hidden get built, so a
 * turned-off card costs no queries. The row builders shared with the old
 * two-scope dashboard (queue rows, announcements, stuck requests) still live in
 * BuildsDashboardData; this trait composes them into widget payloads.
 */
trait BuildsDashboardWidgets
{
    /**
     * The whole dashboard view-model: greeting, the picker catalog, the two-column
     * layout, and a payload per visible widget.
     *
     * @return array{head: array, widgetCatalog: array, widgetLayout: array, widgetPrefs: array, widgets: array}
     */
    private function dashboardData(Request $request, ?Employee $employee, string $role): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $features = app(FeatureManager::class);

        // Role gate first, then the tenant's module switches: a widget whose module
        // is off reads as absent rather than as empty, the same rule screen() applies
        // to whole screens.
        $available = array_values(array_filter(
            DashboardWidgets::forRole($role),
            function (string $id) use ($features, $tenant): bool {
                $screen = DashboardWidgets::gatingScreen($id);

                return $screen === null || $features->screenAllowed($tenant, $screen);
            },
        ));

        $prefs = DashboardPrefs::forUser($request->user()?->dashboard_prefs);
        $layout = DashboardWidgets::layout($available, $prefs['order'], $prefs['hidden']);

        $widgets = [];
        foreach ($layout as $ids) {
            foreach ($ids as $id) {
                $widgets[$id] = $this->dashboardWidget($id, $request, $employee);
            }
        }

        return [
            'head' => $this->meHead($employee),
            'widgetCatalog' => DashboardWidgets::catalog($available),
            'widgetLayout' => $layout,
            'widgetPrefs' => $prefs,
            'widgets' => $widgets,
        ];
    }

    /** One widget's payload. @return array<string, mixed> */
    private function dashboardWidget(string $id, Request $request, ?Employee $employee): array
    {
        return match ($id) {
            'summary' => $this->summaryWidget($employee),
            'clock' => $this->clockWidget($employee),
            'tasks' => $this->tasksWidget($request, $employee),
            'leave' => $this->leaveWidget($employee),
            'stuck' => ['rows' => $this->stuckRows()->all()],
            'calendar' => $this->calendarWidget($request, $employee),
            'attendance' => $this->teamAttendanceWidget($employee),
            'notices' => ['rows' => $this->newsRows($employee)],
            'claims' => $this->claimsWidget($employee),
            'work' => $this->workWidget($employee),
            'pulse' => $this->pulseWidget(),
            default => [],
        };
    }

    /**
     * The month grid, straight from CalendarController so this and the Calendar
     * screen can never drift apart — minus company events when the tenant has the
     * events module switched off, which the calendar screen's own gate would
     * otherwise be the only thing enforcing.
     *
     * @return array<string, mixed>
     */
    private function calendarWidget(Request $request, ?Employee $employee): array
    {
        $data = app(CalendarController::class)->screenData($request, $employee);

        if (app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), 'events')) {
            return $data;
        }

        $data['eventsThisMonth'] = collect();
        $data['weeks'] = array_map(
            fn (array $week) => array_map(fn (array $day) => ['events' => collect()] + $day, $week),
            $data['weeks'],
        );

        return $data;
    }

    /**
     * Six figures for the month so far: hours, overtime, leave, lateness, absence
     * and unfinished shifts. Overtime is minutes worked past the shift's own
     * expected hours on days that are actually finished — the same expectation the
     * record's flags were raised from, so the two can never disagree.
     *
     * @return array{tiles: list<array{k: string, v: string, unit: string, label: string}>}
     */
    private function summaryWidget(?Employee $employee): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $records = $employee
            ? $employee->attendanceRecords()
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<=', $end->toDateString())
                ->get()
            : collect();

        $finished = $records->filter(fn (AttendanceRecord $r) => $r->clock_out !== null);
        $worked = (int) $records->sum('worked_minutes');
        $expected = (int) $finished
            ->filter(fn (AttendanceRecord $r) => $r->expected_min_hours !== null)
            ->sum(fn (AttendanceRecord $r) => (int) round((float) $r->expected_min_hours * 60));

        $leaveDays = $employee
            ? (float) $employee->leaveRequests()
                ->where('status', 'approved')
                ->whereDate('date_from', '<=', $end->toDateString())
                ->whereDate('date_to', '>=', $start->toDateString())
                ->sum('days')
            : 0.0;

        $late = $records->where('status', 'late')->count();
        $absent = $records->filter(fn (AttendanceRecord $r) => $r->clock_in === null)->count();
        // Clocked in, never clocked out, and the day is over: the shift can no longer
        // complete itself, so it is a gap in the record rather than someone still working.
        $incomplete = $records->filter(
            fn (AttendanceRecord $r) => $r->clock_in !== null && $r->clock_out === null && $r->date->isBefore(now()->startOfDay())
        )->count();

        return [
            'tiles' => [
                $this->summaryTile('hours', $worked, 'Work hours'),
                $this->summaryTile('ot', max(0, $worked - $expected), 'Overtime'),
                ['k' => 'leave', 'v' => $this->trimNumber($leaveDays), 'unit' => Str::plural('day', (int) ceil($leaveDays)), 'label' => 'On leave'],
                ['k' => 'late', 'v' => (string) $late, 'unit' => Str::plural('shift', $late), 'label' => 'Late'],
                ['k' => 'absent', 'v' => (string) $absent, 'unit' => Str::plural('shift', $absent), 'label' => 'Absent'],
                ['k' => 'incomplete', 'v' => (string) $incomplete, 'unit' => Str::plural('shift', $incomplete), 'label' => 'Incomplete'],
            ],
        ];
    }

    /** A duration tile: the hours are the figure, the minutes are the unit beside it. */
    private function summaryTile(string $key, int $minutes, string $label): array
    {
        return [
            'k' => $key,
            'v' => (string) intdiv($minutes, 60),
            'unit' => 'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m',
            'label' => $label,
        ];
    }

    /**
     * Today's shift and the last five days of punches.
     *
     * @return array{today: ?AttendanceRecord, punches: list<array>, totalMinutes: int}
     */
    private function clockWidget(?Employee $employee): array
    {
        if (! $employee) {
            return ['today' => null, 'punches' => [], 'totalMinutes' => 0];
        }

        // Same overnight case as meHead(): an open punch from a shift that crossed
        // midnight is dated yesterday, so prefer it over today's (absent) row.
        $today = $employee->attendanceRecords()->openPunch(now())->first()
            ?? $employee->attendanceRecords()->onDate(now())->first();

        $recent = $employee->attendanceRecords()
            ->whereNotNull('clock_in')
            ->orderByDesc('date')
            ->take(5)
            ->get();

        return [
            'today' => $today,
            'punches' => $recent->map(fn (AttendanceRecord $r) => [
                'day' => $r->date->format('D j M'),
                'times' => trim((string) $r->clock_in).' – '.($r->clock_out ? (string) $r->clock_out : 'open'),
                'status' => $r->clock_out === null ? 'warn' : ($r->status === 'late' ? 'bad' : 'ok'),
                'label' => $r->clock_out === null ? 'Open' : ($r->status === 'late' ? 'Late' : 'On time'),
            ])->all(),
            'totalMinutes' => (int) $recent->sum('worked_minutes'),
        ];
    }

    /**
     * Everything still waiting on the viewer, in named groups rather than one flat
     * list: what is theirs to approve, what they owe, and what of theirs is in
     * flight. Groups with nothing in them are dropped, not rendered empty.
     *
     * @return array{groups: list<array{id: string, title: string, count: int, hot: bool, rows: list<array>}>}
     */
    private function tasksWidget(Request $request, ?Employee $employee): array
    {
        $features = app(FeatureManager::class);
        $tenant = app(CurrentTenant::class)->get();

        $blankDays = $this->meTimesheetBlankDays($employee, $features, $tenant);
        $unreadLessons = $this->meUnreadLessonCount($employee, $features, $tenant);

        $groups = [
            ['id' => 'approve', 'title' => 'Waiting on you', 'hot' => true, 'rows' => $this->companyQueueRows($request)],
            ['id' => 'owed', 'title' => 'Your obligations', 'hot' => true, 'rows' => $this->meQueueRows($employee, $features, $tenant, $blankDays, $unreadLessons)],
            ['id' => 'flight', 'title' => 'Your open requests', 'hot' => false, 'rows' => $this->meSecondaryRows($employee, $features, $tenant)],
        ];

        $groups = array_values(array_filter($groups, fn (array $g) => $g['rows']->isNotEmpty()));

        return [
            'groups' => array_map(fn (array $g) => [
                'id' => $g['id'],
                'title' => $g['title'],
                'count' => $g['rows']->count(),
                'hot' => $g['hot'],
                'rows' => $g['rows']->all(),
            ], $groups),
        ];
    }

    /**
     * Entitlement, taken and remaining per leave type. `used` is derived rather
     * than stored: the balance column is the live remainder, so the difference from
     * the type's entitlement is what has actually been taken.
     *
     * @return array{rows: list<array>, pending: int}
     */
    private function leaveWidget(?Employee $employee): array
    {
        if (! $employee) {
            return ['rows' => [], 'pending' => 0];
        }

        $rows = $employee->leaveBalances()->with('leaveType')->get()
            ->filter(fn (LeaveBalance $b) => $b->leaveType !== null)
            ->map(function (LeaveBalance $b) {
                $entitlement = (float) ($b->leaveType->entitlement ?? 0);
                $balance = (float) $b->balance;
                $used = max(0.0, $entitlement - $balance);

                return [
                    'type' => (string) $b->leaveType->name,
                    'entitlement' => $this->trimNumber($entitlement),
                    'used' => $this->trimNumber($used),
                    'balance' => $this->trimNumber($balance),
                    // Guard the zero-entitlement type (unpaid leave), which would
                    // otherwise divide by nothing and render a full bar.
                    'pct' => $entitlement > 0 ? (int) round($used / $entitlement * 100) : 0,
                ];
            })->values()->all();

        return [
            'rows' => $rows,
            'pending' => $employee->leaveRequests()->whereIn('status', ['submitted', 'verified'])->count(),
        ];
    }

    /**
     * Who on the viewer's reporting line is in, late, on leave or absent today.
     * "My staff" means the direct reporting line for every role — HR and the
     * directors get the company-wide picture from Company pulse instead.
     *
     * @return array{counts: list<array{k: string, v: int, label: string}>, people: list<array>}
     */
    private function teamAttendanceWidget(?Employee $employee): array
    {
        if (! $employee) {
            return ['counts' => [], 'people' => []];
        }

        $team = Employee::active()->where('reports_to_id', $employee->id)->orderBy('name')->get();
        if ($team->isEmpty()) {
            return ['counts' => [], 'people' => []];
        }

        $records = AttendanceRecord::whereIn('employee_id', $team->pluck('id'))
            ->onDate(now())
            ->get()
            ->keyBy('employee_id');

        $onLeave = LeaveRequest::whereIn('employee_id', $team->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', now()->toDateString())
            ->whereDate('date_to', '>=', now()->toDateString())
            ->pluck('employee_id')
            ->all();

        $people = $team->map(function (Employee $e) use ($records, $onLeave) {
            $record = $records->get($e->id);
            [$state, $label] = match (true) {
                in_array($e->id, $onLeave, true) => ['leave', 'On leave'],
                $record === null || $record->clock_in === null => ['absent', 'Not clocked in'],
                $record->status === 'late' => ['late', 'Late · '.$record->clock_in],
                $record->clock_out !== null => ['done', 'Out at '.$record->clock_out],
                default => ['in', 'In at '.$record->clock_in],
            };

            return [
                'name' => $e->display_name,
                'initials' => $e->initials,
                'color' => $e->avatar_color,
                'state' => $state,
                'label' => $label,
            ];
        })->values();

        return [
            'counts' => array_map(fn (array $c) => [
                'k' => $c[0],
                'v' => $people->where('state', $c[0])->count(),
                'label' => $c[1],
            ], [['in', 'In'], ['late', 'Late'], ['done', 'Done'], ['leave', 'Leave'], ['absent', 'Absent']]),
            'people' => $people->all(),
        ];
    }

    /**
     * This calendar year's claims, grouped by type, with what is still owed to the
     * viewer. Approved-but-unpaid is the number that matters here: a submitted
     * claim is a request, an approved one is money the company owes you.
     *
     * @return array{rows: list<array>, awaiting: float}
     */
    private function claimsWidget(?Employee $employee): array
    {
        if (! $employee) {
            return ['rows' => [], 'awaiting' => 0.0];
        }

        $claims = $employee->claims()
            ->whereYear('date', now()->year)
            ->get();

        $rows = $claims->groupBy('type')->map(fn (Collection $forType, string $type) => [
            'type' => ucfirst($type),
            'amount' => (float) $forType->where('status', '!=', 'rejected')->sum('amount'),
            'count' => $forType->count(),
            'status' => $forType->contains(fn (Claim $c) => in_array($c->status, ['submitted', 'verified'], true))
                ? 'Pending'
                : 'Settled',
        ])->sortByDesc('amount')->values()->all();

        return [
            'rows' => $rows,
            'awaiting' => (float) $claims->whereIn('status', ['submitted', 'verified', 'approved'])->sum('amount'),
        ];
    }

    /**
     * The viewer's own clock-in/clock-out log for the current month, newest first.
     *
     * @return array{rows: list<array>}
     */
    private function workWidget(?Employee $employee): array
    {
        if (! $employee) {
            return ['rows' => []];
        }

        $rows = $employee->attendanceRecords()
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->orderByDesc('date')
            ->take(10)
            ->get()
            ->map(fn (AttendanceRecord $r) => [
                'day' => $r->date->format('D'),
                'date' => $r->date->format('j M'),
                'shift' => (string) ($r->location ?: ucfirst((string) $r->type)),
                'in' => $r->clock_in ? (string) $r->clock_in : '—',
                'out' => $r->clock_out ? (string) $r->clock_out : '—',
            ])->all();

        return ['rows' => $rows];
    }

    /**
     * Four company-wide figures for the people who answer for them.
     *
     * @return array{stats: list<array{v: string, label: string, hot: bool}>}
     */
    private function pulseWidget(): array
    {
        $tenant = app(CurrentTenant::class)->get();

        $lateTimesheets = app(TimesheetCompliance::class)
            ->roster($tenant, now()->startOfWeek())
            ->where('status', 'late')
            ->count();

        $owed = (float) Claim::whereIn('status', ['verified', 'approved'])->sum('amount');

        return [
            'stats' => [
                ['v' => (string) $lateTimesheets, 'label' => 'Timesheets past lock', 'hot' => $lateTimesheets > 0],
                ['v' => (string) ($tenant?->employees()->active()->count() ?? 0), 'label' => 'Active headcount', 'hot' => false],
                ['v' => (string) Employee::active()->where('status', 'on_leave')->count(), 'label' => 'On leave today', 'hot' => false],
                ['v' => 'RM '.number_format($owed, 0), 'label' => 'Claims awaiting payout', 'hot' => false],
            ],
        ];
    }
}
