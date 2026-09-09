<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Attendance\HolidayEve;
use App\Http\Controllers\BigDealController;
use App\Http\Controllers\BirthdayWishController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\VictoryBellController;
use App\Models\AttendanceRecord;
use App\Models\BigDeal;
use App\Models\Claim;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Flower;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\VictoryBell;
use App\Services\DataScope;
use App\Services\FeatureManager;
use App\Support\ArchetypeCatalog;
use App\Support\ArchetypeScorer;
use App\Support\AwardBoard;
use App\Support\DashboardBands;
use App\Support\DashboardPrefs;
use App\Support\DashboardWidgets;
use App\Support\ManagementExceptions;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Carbon\CarbonImmutable;
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
     * @return array{head: array, egg: array|null, bands: array, widgetCatalog: array, widgetLayout: array, widgetPrefs: array, widgets: array}
     */
    private function dashboardData(Request $request, ?Employee $employee, string $role): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $features = app(FeatureManager::class);

        // Role gate first, then the tenant's module switches: a widget whose module
        // is off reads as absent rather than as empty, the same rule screen() applies
        // to whole screens.
        $now = CarbonImmutable::now();
        $available = array_values(array_filter(
            DashboardWidgets::forRole($role),
            function (string $id) use ($features, $tenant, $now): bool {
                // The Friday sign-off (CR-32 slot) is a card only inside its window;
                // outside it the card is absent, not empty, so it leaves the picker too.
                if ($id === 'friday' && ! DashboardWidgets::fridaySignOffOpen($now)) {
                    return false;
                }
                // Same idea for 'events': absent on any day without a same-day company
                // event carrying attendees, not just empty.
                if ($id === 'events' && $this->todaysDashboardEvent($now) === null) {
                    return false;
                }
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

        // Flowers (CR-23) doesn't exist as a card when nobody has been given one this
        // month — unlike every other widget here, it has no useful empty state.
        if (($widgets['flowers']['rows'] ?? null) === []) {
            unset($widgets['flowers']);
            foreach (DashboardWidgets::COLUMNS as $column) {
                $layout[$column] = array_values(array_diff($layout[$column], ['flowers']));
            }
        }

        return [
            'head' => $this->meHead($request, $employee),
            'egg' => $this->dashboardEgg($employee, $now, (bool) ($prefs['plain'] ?? false)),
            'bands' => $this->dashboardBands($employee, $role),
            'widgetCatalog' => DashboardWidgets::catalog($available),
            'widgetLayout' => $layout,
            'widgetPrefs' => $prefs,
            'widgets' => $widgets,
        ];
    }

    /**
     * The three full-width bands above the grid (CR-32). Each slot is null when
     * nothing is active; the view renders nothing for a null slot. The moments
     * list grows as CR-13/22/24/28 land; the management and awards slots stay
     * null until CR-17 and CR-14.
     *
     * @return array{moments: list<array<string, mixed>>, moments_start: int, management: array<string, mixed>|null, awards: array<string, mixed>|null, upcoming: list<array{name: string, date: string}>}
     */
    private function dashboardBands(?Employee $employee, string $role): array
    {
        $today = CarbonImmutable::now();
        $moments = [];
        $upcoming = [];
        $management = null;
        $awards = null;

        if ($employee !== null) {
            $eve = DashboardBands::holidayEveMoment(app(HolidayEve::class), $today);
            if ($eve !== null) {
                $moments[] = $eve;
            }

            // Weekend or a public-holiday row for this tenant — the only two ways a day
            // is not a working day (CR-13's celebratedOn()).
            $isWorkingDay = fn (CarbonImmutable $day): bool => ! $day->isWeekend()
                && ! PublicHoliday::whereDate('date', $day->toDateString())->exists();

            $celebratedDates = DashboardBands::celebratedOn($today, $isWorkingDay);
            $monthDayPairs = collect($celebratedDates)->map(fn (CarbonImmutable $d) => [$d->month, $d->day]);

            $people = Employee::active()->where('birthday_private', false)->whereNotNull('date_of_birth')
                ->where(function ($q) use ($monthDayPairs) {
                    foreach ($monthDayPairs as [$month, $day]) {
                        $q->orWhere(fn ($sub) => $sub->whereMonth('date_of_birth', $month)->whereDay('date_of_birth', $day));
                    }
                })
                ->get();
            // The trading name reads better in a wish than the registered one ("Unijaya", not "Unijaya Resources Sdn Bhd").
            $tenantName = trim((string) preg_replace('/\s+(Resources|Holdings|Enterprise|Group)?\s*(Sdn\.?\s*Bhd\.?|Berhad|Bhd\.?)$/i', '', (string) (app(CurrentTenant::class)->get()->name ?? '')));
            $birthdayMoments = DashboardBands::birthdayMoments($people, $today, $celebratedDates, $tenantName, $employee->id);

            // Each birthday moment carries its wishes region pre-rendered, so the band
            // shows the composer/list on first paint rather than an extra round trip.
            $wishController = app(BirthdayWishController::class);
            $peopleById = $people->keyBy('id');
            foreach ($birthdayMoments as &$moment) {
                $celebrant = $peopleById->get($moment['employee']['id']);
                if ($celebrant !== null) {
                    $moment['wishesHtml'] = $wishController->wishesPartial($celebrant, $employee);
                }
            }
            unset($moment);

            $moments = [...$moments, ...$birthdayMoments];

            // CR-24: every Big Deal still inside its 3-day window, one moment each.
            // The reaction region is stitched in afterward, same as a birthday
            // moment's wishesHtml, since it needs the controller's partial renderer.
            $bigDeals = BigDeal::with(['members', 'photos', 'raisedBy', 'project'])->get()->keyBy('id');
            $bigDealMoments = DashboardBands::bigDealMoments($bigDeals, $today);
            $bigDealController = app(BigDealController::class);
            foreach ($bigDealMoments as &$moment) {
                $moment['reactHtml'] = $bigDealController->reactPartial($bigDeals[$moment['big_deal_id']], $employee);
            }
            unset($moment);
            $moments = [...$moments, ...$bigDealMoments];

            // CR-28: every Victory Bell still inside its 24-hour window, one moment
            // each, appended after Big Deal moments (docs/build/OPEN.md — arbitrary,
            // reversible ordering, nothing in the spec or test pins it).
            $bells = VictoryBell::with(['workItem.participants', 'workItem.employee', 'project', 'rungBy'])->get()->keyBy('id');
            $bellMoments = DashboardBands::victoryBellMoments($bells, $today);
            $bellController = app(VictoryBellController::class);
            foreach ($bellMoments as &$moment) {
                $moment['reactHtml'] = $bellController->reactPartial($bells[$moment['victory_bell_id']], $employee);
            }
            unset($moment);
            $moments = [...$moments, ...$bellMoments];

            $celebratedTodayIds = collect($birthdayMoments)->mapWithKeys(fn (array $m) => [$m['employee']['id'] => true])->all();
            $upcomingPeople = Employee::active()->where('birthday_private', false)->whereNotNull('date_of_birth')->get();
            $upcoming = DashboardBands::upcomingBirthdays($upcomingPeople, $today, $celebratedTodayIds);

            // CR-32 slots: management for the final-approval roles every day (CR-17
            // fills it), awards from the first working day to the 7th (CR-14 fills it).
            if (in_array($role, Permissions::FINAL_APPROVAL_ROLES, true)) {
                $exceptions = app(ManagementExceptions::class);
                $management = DashboardBands::managementSlot(
                    $exceptions->lateness(null),
                    $exceptions->withReassignFlags($exceptions->overdue(null), $employee, $role),
                );
            }
            if (DashboardBands::awardsWindowOpen($today, $isWorkingDay)) {
                $previousMonth = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
                $awards = DashboardBands::awardsSlot($today, AwardBoard::slidesForMonth($previousMonth));
            }
        }

        return DashboardBands::compose($moments, $management, $awards, $today, $upcoming);
    }

    /**
     * One widget's payload, for the period the viewer is looking at.
     *
     * `$at` is the raw arrow value from the query string (`2026-08-31`, `2026-08`,
     * `2026`); null means the current period. Widgets without arrows ignore it.
     *
     * @return array<string, mixed>
     */
    private function dashboardWidget(string $id, Request $request, ?Employee $employee, ?string $at = null): array
    {
        // The calendar widget already honoured `?month=` on the dashboard URL before
        // it had arrows; keep that working by treating it as the same value.
        if ($id === 'calendar' && $at === null && is_string($request->query('month'))) {
            $at = $request->query('month');
        }

        $when = $this->dashboardPeriodStart($id, $at);

        $payload = match ($id) {
            'summary' => $this->summaryWidget($employee),
            'clock' => $this->clockWidget($employee, $when),
            'tasks' => $this->tasksWidget($request, $employee),
            'leave' => $this->leaveWidget($employee),
            'stuck' => ['rows' => $this->stuckRows()->all()],
            'calendar' => $this->calendarWidget($request, $employee, $when),
            'attendance' => $this->teamAttendanceWidget($employee, $when),
            'notices' => ['rows' => $this->newsRows($employee)],
            'flowers' => $this->flowersWidget($request),
            'friday' => ['plain' => (bool) DashboardPrefs::forUser($request->user()?->dashboard_prefs)['plain']],
            'claims' => $this->claimsWidget($employee, $when),
            'work' => $this->workWidget($employee, $when),
            'style' => $this->styleWidget($employee),
            'pulse' => $this->pulseWidget(),
            'events' => $this->eventsWidget() + ['plain' => (bool) DashboardPrefs::forUser($request->user()?->dashboard_prefs)['plain']],
            default => [],
        };

        $pnav = $this->dashboardPnav($id, $when);

        return $pnav === null ? $payload : $payload + ['pnav' => $pnav];
    }

    /**
     * The start of the period a widget should render, from the raw arrow value.
     *
     * Anything unparseable falls back to now rather than erroring: the value comes
     * off a query string, and a bad one should show today's card, not a 500. The
     * same goes for a future period on a backward-looking widget.
     */
    private function dashboardPeriodStart(string $id, ?string $at): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $unit = DashboardWidgets::periodUnit($id);

        if ($unit === null || $at === null) {
            return $now;
        }

        try {
            $start = match ($unit) {
                'day' => CarbonImmutable::createFromFormat('Y-m-d', $at)->startOfDay(),
                'month' => CarbonImmutable::createFromFormat('Y-m', $at)->startOfMonth(),
                default => CarbonImmutable::createFromFormat('Y', $at)->startOfYear(),
            };
        } catch (\Throwable) {
            return $now;
        }

        return ! DashboardWidgets::allowsFuture($id) && $start->isAfter($now) ? $now : $start;
    }

    /**
     * The arrows themselves: what the label reads, and where each side goes.
     *
     * `next` is null once the card is showing the current period, so a log of what
     * happened cannot be walked into a future it has nothing to say about. The
     * calendar is the exception and never runs out of forward.
     *
     * @return array{unit: string, label: string, prev: string, next: ?string, isNow: bool}|null
     */
    private function dashboardPnav(string $id, CarbonImmutable $when): ?array
    {
        $unit = DashboardWidgets::periodUnit($id);

        if ($unit === null) {
            return null;
        }

        [$label, $value] = match ($unit) {
            'day' => ['j M Y', 'Y-m-d'],
            'month' => ['M Y', 'Y-m'],
            default => ['Y', 'Y'],
        };

        $isNow = $when->format($value) === CarbonImmutable::now()->format($value);

        return [
            'unit' => $unit,
            'label' => $when->format($label),
            'prev' => $when->sub($unit, 1)->format($value),
            'next' => $isNow && ! DashboardWidgets::allowsFuture($id) ? null : $when->add($unit, 1)->format($value),
            'isNow' => $isNow,
        ];
    }

    /**
     * The month grid, straight from CalendarController so this and the Calendar
     * screen can never drift apart — minus company events when the tenant has the
     * events module switched off, which the calendar screen's own gate would
     * otherwise be the only thing enforcing.
     *
     * @return array<string, mixed>
     */
    private function calendarWidget(Request $request, ?Employee $employee, CarbonImmutable $when): array
    {
        // screenData reads the month off the query string; the arrows have already
        // been folded into $when, so hand it back the month it should draw.
        $request->query->set('month', $when->format('Y-m'));

        $data = app(CalendarController::class)->screenData($request, $employee);

        if (! app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), 'events')) {
            $data['eventsThisMonth'] = collect();
            $data['weeks'] = array_map(
                fn (array $week) => array_map(fn (array $day) => ['events' => collect()] + $day, $week),
                $data['weeks'],
            );
        }

        return $data + $this->calendarDays($data['weeks'], $employee, $data['leaveTypeIds']);
    }

    /**
     * The day-by-day reading of the same month grid: what sits on each date, who
     * it belongs to, and how much of it each tab shows.
     *
     * A tab is a widening circle rather than a filter — Personal is your own leave
     * plus the dates that apply to everyone (holidays, company events), Team adds
     * everyone below you in the org chart, Company is the lot. So an entry carries the
     * narrowest tab it belongs to and every wider tab shows it too. The Team tab
     * is left out entirely for someone with nobody under them; it would be
     * a copy of Personal.
     *
     * @param  list<list<array<string, mixed>>>  $weeks
     * @param  list<int>|null  $leaveTypeIds  whose leave type this viewer may read
     * @return array{days: array<string, array<string, mixed>>, calTabs: list<string>, selected: string}
     */
    private function calendarDays(array $weeks, ?Employee $employee, ?array $leaveTypeIds): array
    {
        $reports = $this->dashboardTeamIds($employee);
        $ownPending = $this->ownPendingLeave($weeks, $employee);

        $days = [];
        $selected = null;

        foreach ($weeks as $week) {
            foreach ($week as $day) {
                if (! $day['inMonth']) {
                    continue;
                }

                $key = $day['date']->toDateString();
                $entries = $this->calendarEntries($day, $employee, $reports, $ownPending, $leaveTypeIds);

                $days[$key] = [
                    'label' => $day['date']->format('j F'),
                    'entries' => $entries,
                    'marks' => $this->calendarMarks($entries),
                ];

                // Land on today, or on the first of the month when the viewer is
                // looking at a month they are not standing in.
                if ($selected === null || $day['isToday']) {
                    $selected = $key;
                }
            }
        }

        return [
            'days' => $days,
            'calTabs' => $reports === [] ? ['personal', 'company'] : ['personal', 'team', 'company'],
            'selected' => $selected ?? now()->toDateString(),
        ];
    }

    /**
     * One day's entries, each tagged with the narrowest tab that shows it:
     * 0 personal, 1 team, 2 company.
     *
     * @param  array<string, mixed>  $day
     * @param  list<int>  $reports
     * @param  Collection<int, LeaveRequest>  $ownPending
     * @param  list<int>|null  $leaveTypeIds  whose leave type this viewer may read
     * @return list<array{level: int, kind: string, who: string, short: string, title: string, sub: string}>
     */
    private function calendarEntries(array $day, ?Employee $employee, array $reports, Collection $ownPending, ?array $leaveTypeIds): array
    {
        $entries = [];

        foreach ($day['holiday'] as $holiday) {
            $entries[] = ['level' => 0, 'kind' => 'holiday', 'who' => 'PH',
                'short' => (string) $holiday->name,
                'title' => (string) $holiday->name, 'sub' => 'Public holiday'];
        }

        foreach ($day['events'] as $event) {
            $entries[] = ['level' => 0, 'kind' => 'event', 'who' => 'EV',
                'short' => (string) $event->title,
                'title' => (string) $event->title, 'sub' => 'Company event'];
        }

        foreach ($day['leave'] as $leave) {
            $person = $leave->employee;
            $mine = $employee !== null && $person->id === $employee->id;
            $name = $mine ? 'You' : $person->display_name;
            // "Medical" beside a colleague's name is their health, not company news.
            // Everyone still sees that they are away; only their own managers and the
            // roles that administer leave see what kind (Permissions::leaveTypeAudience).
            $type = Permissions::showsLeaveType($leaveTypeIds, $person->id)
                ? Str::lower($this->leaveTypeName($leave))
                : 'on leave';

            $entries[] = [
                'level' => match (true) {
                    $mine => 0,
                    in_array($person->id, $reports, true) => 1,
                    default => 2,
                },
                'kind' => 'leave',
                'who' => $mine ? 'You' : $this->initials($name),
                // The grid cell says who, in as many letters as the cell can hold.
                // Initials alone were a single letter for most people here — every
                // display name is one word — which named nobody.
                'short' => $name,
                'title' => $name.' — '.$type,
                'sub' => $leave->date_from->isSameDay($leave->date_to)
                    ? 'All day'
                    : $leave->date_from->format('j M').' – '.$leave->date_to->format('j M'),
            ];
        }

        // Your own leave that has not been approved yet is on the calendar screen
        // nowhere, but it is the thing you most want to see on your own dashboard:
        // the day you asked for is already spoken for, pending or not.
        foreach ($ownPending as $leave) {
            if (! $day['date']->betweenIncluded($leave->date_from, $leave->date_to)) {
                continue;
            }

            $entries[] = [
                'level' => 0,
                'kind' => 'pending',
                'who' => 'You',
                'short' => 'You',
                'title' => 'You — '.Str::lower($this->leaveTypeName($leave)),
                // Verified means a superior has already passed it up; the only thing
                // left is final approval.
                'sub' => $leave->verified_at !== null
                    ? 'Waiting for approval'
                    : 'Waiting to be verified',
            ];
        }

        return $entries;
    }

    /**
     * The viewer's own submitted-or-verified leave overlapping the visible grid.
     * Loaded once for the whole month rather than per cell.
     *
     * @param  list<list<array<string, mixed>>>  $weeks
     * @return Collection<int, LeaveRequest>
     */
    private function ownPendingLeave(array $weeks, ?Employee $employee): Collection
    {
        if ($employee === null || $weeks === []) {
            return collect();
        }

        $first = $weeks[0][0]['date'];
        $lastWeek = $weeks[count($weeks) - 1];
        $last = $lastWeek[count($lastWeek) - 1]['date'];

        return LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['submitted', 'verified'])
            ->whereDate('date_from', '<=', $last->toDateString())
            ->whereDate('date_to', '>=', $first->toDateString())
            ->get();
    }

    /**
     * The pills a grid cell carries, per tab. Built here rather than in the view
     * because "the first two, and how many are left" is a different answer for
     * each tab, and a cell can only show one tab's worth.
     *
     * A pill reads the entry's short name — the person, the holiday, the event —
     * and the cell clips it with an ellipsis. Initials were tried and read as one
     * letter: every display name here is a single word, so "N" was the whole pill.
     *
     * @param  list<array{level: int, kind: string, who: string, short: string, title: string, sub: string}>  $entries
     * @return array<string, array{pills: list<array{kind: string, label: string}>, more: int, count: int}>
     */
    private function calendarMarks(array $entries): array
    {
        $marks = [];

        foreach (['personal' => 0, 'team' => 1, 'company' => 2] as $tab => $level) {
            $shown = array_values(array_filter($entries, fn (array $e): bool => $e['level'] <= $level));

            $marks[$tab] = [
                'count' => count($shown),
                'more' => max(0, count($shown) - 2),
                'pills' => array_map(
                    fn (array $e): array => [
                        'kind' => $e['kind'],
                        'label' => $e['short'],
                    ],
                    array_slice($shown, 0, 2),
                ),
            ];
        }

        return $marks;
    }

    /** A request's leave type, or a plain word when the type row has gone. */
    private function leaveTypeName(LeaveRequest $leave): string
    {
        return (string) ($leave->leaveType?->name ?? 'leave');
    }

    /** Two-letter stand-in for a face, from a display name. */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_slice(array_filter(array_map(
            fn (string $p): string => mb_substr($p, 0, 1),
            $parts,
        )), 0, 2);

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }

    /**
     * "My team" for every card that shows one: everyone below the viewer in the
     * org chart at any depth, not just their direct reports, plus their
     * dotted-line reports. Grandchildren count — a manager of managers still
     * manages the people two rungs down, and a card that skipped them would
     * report a team the viewer does not recognise as theirs.
     *
     * Shared so team attendance, the month summary's staff view and the
     * calendar's Team tab always draw the same set of people.
     *
     * @return list<int>
     */
    private function dashboardTeamIds(?Employee $employee): array
    {
        return $employee ? app(DataScope::class)->teamIds($employee) : [];
    }

    /**
     * The month summary, in two views: your own, and everyone reporting to you.
     *
     * "My staff" is the whole reporting line below the viewer, the same set Team
     * attendance uses. The mock gated the toggle at manager and above, but having
     * reports is the honest gate: an HR person with nobody under them has no staff
     * view to show, and a director with reports does. Nobody under you means no
     * second view at all.
     *
     * @return array{tiles: list<array{k: string, v: string, unit: string, label: string}>, staffTiles: ?list<array{k: string, v: string, unit: string, label: string}>}
     */
    private function summaryWidget(?Employee $employee): array
    {
        $staff = Employee::active()->whereIn('id', $this->dashboardTeamIds($employee))->pluck('id')->all();

        return [
            'tiles' => $this->monthTiles($employee ? [$employee->id] : []),
            'staffTiles' => $staff === [] ? null : $this->monthTiles($staff),
        ];
    }

    /**
     * Six figures for the month so far: hours, overtime, leave, lateness, absence
     * and unfinished shifts. Overtime is minutes worked past the shift's own
     * expected hours on days that are actually finished — the same expectation the
     * record's flags were raised from, so the two can never disagree. Over several
     * people it nets the same way it nets over several days: a short day cancels an
     * overtime day, and the tile reads what the team owed against what it worked.
     *
     * @param  list<int>  $ids
     * @return list<array{k: string, v: string, unit: string, label: string}>
     */
    private function monthTiles(array $ids): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $records = $ids === [] ? collect() : AttendanceRecord::whereIn('employee_id', $ids)
            ->where('date', '>=', $start->toDateString())
            // Exclusive: `date` stores a time, so `<=` the last of the month would
            // compare against its midnight and drop that day's shift.
            ->where('date', '<', $end->copy()->addDay()->toDateString())
            ->get();

        $finished = $records->filter(fn (AttendanceRecord $r) => $r->clock_out !== null);
        $worked = (int) $records->sum('worked_minutes');
        $expected = (int) $finished
            ->filter(fn (AttendanceRecord $r) => $r->expected_min_hours !== null)
            ->sum(fn (AttendanceRecord $r) => (int) round((float) $r->expected_min_hours * 60));

        $leaveDays = $ids === [] ? 0.0 : (float) LeaveRequest::whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->sum('days');

        $late = $records->where('status', 'late')->count();
        $absent = $records->filter(fn (AttendanceRecord $r) => $r->clock_in === null)->count();
        // Clocked in, never clocked out, and the day is over: the shift can no longer
        // complete itself, so it is a gap in the record rather than someone still working.
        $incomplete = $records->filter(
            fn (AttendanceRecord $r) => $r->clock_in !== null && $r->clock_out === null && $r->date->isBefore(now()->startOfDay())
        )->count();

        return [
            $this->summaryTile('hours', $worked, 'Work hours'),
            $this->summaryTile('ot', max(0, $worked - $expected), 'Overtime'),
            ['k' => 'leave', 'v' => $this->trimNumber($leaveDays), 'unit' => Str::plural('day', (int) ceil($leaveDays)), 'label' => 'On leave'],
            ['k' => 'late', 'v' => (string) $late, 'unit' => Str::plural('shift', $late), 'label' => 'Late'],
            ['k' => 'absent', 'v' => (string) $absent, 'unit' => Str::plural('shift', $absent), 'label' => 'Absent'],
            ['k' => 'incomplete', 'v' => (string) $incomplete, 'unit' => Str::plural('shift', $incomplete), 'label' => 'Incomplete'],
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
    private function clockWidget(?Employee $employee, CarbonImmutable $when): array
    {
        if (! $employee) {
            return ['today' => null, 'punches' => [], 'totalMinutes' => 0];
        }

        // Same overnight case as meHead(): an open punch from a shift that crossed
        // midnight is dated yesterday, so prefer it over the picked day's (absent) row.
        $today = $employee->attendanceRecords()->openPunch($when)->first()
            ?? $employee->attendanceRecords()->onDate($when)->first();

        // The five punches leading up to the picked day, not the five newest on
        // record — arrowing back has to move the list, or the card would keep
        // showing this week under last month's date.
        $recent = $employee->attendanceRecords()
            ->whereNotNull('clock_in')
            // Upper bound is the NEXT day, exclusive: `date` carries a midnight time
            // component, so a `<=` against the day itself would drop the day itself.
            ->where('date', '<', $when->addDay()->toDateString())
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
            })
            // The card shows the first three and folds the rest away, so the
            // biggest entitlements (annual, medical) must come first — the one-day
            // allowances are the tail, whatever order the balances were created in.
            ->sortByDesc(fn (array $r): float => (float) $r['entitlement'])
            ->values()->all();

        return [
            'rows' => $rows,
            'pending' => $employee->leaveRequests()->whereIn('status', ['submitted', 'verified'])->count(),
        ];
    }

    /**
     * Who on the viewer's reporting line is in, late, on leave or absent on the
     * day the arrows are pointing at. The line runs all the way down, so a
     * manager of managers sees their managers' people too. HR and the directors
     * get the company-wide picture from Company pulse instead.
     *
     * @return array{counts: list<array{k: string, v: int, label: string}>, people: list<array>}
     */
    private function teamAttendanceWidget(?Employee $employee, CarbonImmutable $when): array
    {
        if (! $employee) {
            return ['counts' => [], 'people' => []];
        }

        $team = Employee::active()->whereIn('id', $this->dashboardTeamIds($employee))->orderBy('name')->get();
        if ($team->isEmpty()) {
            return ['counts' => [], 'people' => []];
        }

        $records = AttendanceRecord::whereIn('employee_id', $team->pluck('id'))
            ->onDate($when)
            ->get()
            ->keyBy('employee_id');

        $onLeave = LeaveRequest::whereIn('employee_id', $team->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $when->toDateString())
            ->whereDate('date_to', '>=', $when->toDateString())
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

        $counts = array_map(fn (array $c) => [
            'k' => $c[0],
            'v' => $people->where('state', $c[0])->count(),
            'label' => $c[1],
        ], [['in', 'In'], ['late', 'Late'], ['done', 'Done'], ['leave', 'Leave'], ['absent', 'Absent']]);

        // Only the first few show, so the people whose day needs looking at come
        // first: nobody should have to open the fold to find out someone is missing.
        $order = ['absent' => 0, 'late' => 1, 'leave' => 2, 'in' => 3, 'done' => 4];

        return [
            'counts' => $counts,
            'people' => $people
                ->sortBy(fn (array $p): array => [$order[$p['state']], $p['name']])
                ->values()
                ->all(),
        ];
    }

    /**
     * One calendar year's claims, grouped by type, with what is still owed to the
     * viewer. Approved-but-unpaid is the number that matters here: a submitted
     * claim is a request, an approved one is money the company owes you.
     *
     * @return array{rows: list<array>, awaiting: float}
     */
    private function claimsWidget(?Employee $employee, CarbonImmutable $when): array
    {
        if (! $employee) {
            return ['rows' => [], 'awaiting' => 0.0];
        }

        $claims = $employee->claims()
            ->whereYear('date', $when->year)
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
     * The viewer's own clock-in/clock-out log for one month, newest first.
     *
     * @return array{rows: list<array>}
     */
    private function workWidget(?Employee $employee, CarbonImmutable $when): array
    {
        if (! $employee) {
            return ['rows' => []];
        }

        $rows = $employee->attendanceRecords()
            ->where('date', '>=', $when->startOfMonth()->toDateString())
            // Exclusive upper bound for the same reason as the clock log: `date`
            // stores a time, so `<=` the last of the month loses the last of the month.
            ->where('date', '<', $when->addMonth()->startOfMonth()->toDateString())
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
     * The viewer's Profile Test outcome (CR-15): the sidebar entry is gone, so this
     * card is where the test is discovered and where its result is read back.
     *
     * @return array{archetype: ?string, label: string, emoji: string, tagline: string, bars: list<array{key: string, label: string, emoji: string, pct: int, accent: string}>, url: string}
     */
    private function styleWidget(?Employee $employee): array
    {
        $result = $employee?->profileTestResult;
        $key = $result?->animal_archetype;
        $totals = is_array($result?->totals) ? $result->totals : [];
        $answered = array_sum($totals);
        $meta = ArchetypeCatalog::get($key);

        $bars = [];
        foreach (ArchetypeScorer::ORDER as $animal) {
            $bars[] = [
                'key' => $animal,
                'label' => ArchetypeCatalog::get($animal)['label'],
                'emoji' => ArchetypeCatalog::emoji($animal),
                'pct' => $answered ? (int) round(($totals[$animal] ?? 0) / $answered * 100) : 0,
                'accent' => ArchetypeCatalog::get($animal)['accent'],
            ];
        }

        return [
            'archetype' => $key,
            'label' => $key ? $meta['label'] : '',
            'emoji' => ArchetypeCatalog::emoji($key),
            'tagline' => $key ? $meta['tagline_en'] : '',
            'bars' => $bars,
            'url' => route('app.screen', 'profile-test'),
        ];
    }

    /**
     * Latest 10 "Caught Being Brilliant" flowers given anywhere in the tenant
     * this month (CR-23), newest first. Absent as a card entirely when there
     * are none to show — see dashboardData(), which strips it before layout.
     *
     * @return array{rows: list<array{title: string, sub: string, meta: string}>, plain: bool}
     */
    private function flowersWidget(Request $request): array
    {
        $rows = Flower::with(['giver', 'recipient'])->visible()
            ->where('month', now()->format('Y-m'))
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn (Flower $f): array => [
                'title' => ($f->giver?->display_name ?? '—').' → '.($f->recipient?->display_name ?? '—'),
                'sub' => (string) $f->note,
                'meta' => (string) $f->created_at?->diffForHumans(),
            ])->all();

        return [
            'rows' => $rows,
            // "Keep it plain": text still shows, the card just drops the emoji burst.
            'plain' => (bool) DashboardPrefs::forUser($request->user()?->dashboard_prefs)['plain'],
        ];
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

    /**
     * The company event the 'events' widget shows, if any: one with attendees that
     * either starts within the next 30 days or ended within the last 7 — the window
     * OPEN.md's "QA / CR-11" entry pins for dashboard-slots.md's "upcoming or
     * just-past". The date filter is a coarse pre-filter; the exact boundary is
     * checked against starts_at/ends_at in PHP.
     */
    private function todaysDashboardEvent(CarbonImmutable $now): ?CompanyEvent
    {
        return CompanyEvent::with(['rsvps.employee:id,name,nickname', 'photos', 'lessons.employee:id,name,nickname'])
            ->whereDate('event_date', '>=', $now->subDays(8)->toDateString())
            ->whereDate('event_date', '<=', $now->addDays(31)->toDateString())
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get()
            ->first(function (CompanyEvent $e) use ($now) {
                if ($e->rsvps->isEmpty()) {
                    return false;
                }
                $start = $e->startsAtOrDate();
                $end = $e->endsAtOrDate();

                return $now->between($start, $end)
                    || $start->between($now, $now->addDays(30))
                    || $end->between($now->subDays(7), $now);
            });
    }

    /**
     * The upcoming-or-just-past company event card: who's going before it happens,
     * a few photos and the newest lesson line once it's over. Absent entirely (see
     * dashboardData()'s 'events' filter) rather than empty when there is none.
     *
     * @return array{event: ?CompanyEvent, isPast: bool, date: string, attendees: list<string>, photos: list<mixed>, lessonLine: ?string}
     */
    private function eventsWidget(): array
    {
        $event = $this->todaysDashboardEvent(CarbonImmutable::now());
        if (! $event) {
            return ['event' => null];
        }

        $isPast = $event->isOver();

        return [
            'event' => $event,
            'isPast' => $isPast,
            'date' => $event->startsAtOrDate()->format('j M Y'),
            'attendees' => $event->rsvps->map(fn ($r) => (string) $r->employee?->display_name)->filter()->values()->all(),
            'photos' => $isPast ? $event->photos->take(4)->values()->all() : [],
            'lessonLine' => $isPast ? $event->lessons->sortByDesc('id')->first()?->learnt : null,
        ];
    }
}
