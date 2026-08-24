<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\Claim;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExternalTotEvent;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeRead;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Support\RequestGuidance;
use App\Support\StuckRequests;
use App\Support\WorkforceInsights;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Dashboard, achievements and reviews screen data for AppController::screen().
 * Split out of AppController purely for file size — every method still runs on
 * the controller instance ($this), so cross-trait calls keep working.
 */
trait BuildsDashboardData
{
    /**
     * The two-step request types the dashboard queues route: leave and claims only.
     * Overtime is a real screen but ships OFF by default (Features::OFF), so it is
     * deliberately absent here rather than filtered per-tenant every call; a tenant
     * that later turns it on would need this list extended, not just a flag flipped.
     *
     * @var list<array{0: string, 1: string, 2: string}> [type, label, gating screen]
     */
    private const QUEUE_SOURCES = [
        ['leave', 'Leave', 'leave'],
        ['claim', 'Claim', 'claims'],
    ];

    /**
     * Build the full view-model for one dashboard scope ('me' or 'company'). This is
     * the single entry point AppController::screen() calls for the 'dash' screen —
     * everything else in this file below it is a private helper.
     *
     * @return array{head: array, chips: array, queue: Collection, queueMeta: array,
     *     secondary: Collection, secondaryMeta: array, railCards: array}
     */
    private function dashboardScopeData(Request $request, string $scope, ?Employee $employee): array
    {
        return $scope === 'company'
            ? $this->companyScopeData($request)
            : $this->meScopeData($employee);
    }

    /**
     * "What do I owe, and where is my stuff stuck?" — the viewer's own obligations
     * (timesheet, clock, unread lessons) plus their own leave/claim requests in flight.
     */
    private function meScopeData(?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $features = app(FeatureManager::class);

        $blankDays = $this->meTimesheetBlankDays($employee, $features, $tenant);
        $unreadLessons = $this->meUnreadLessonCount($employee, $features, $tenant);

        return [
            'head' => $this->meHead($employee),
            'chips' => [
                ['n' => (string) $blankDays, 'label' => 'timesheet days blank', 'hot' => $blankDays > 0],
                ['n' => $this->trimNumber($employee?->annualLeaveBalance() ?? 0.0), 'label' => 'annual leave days', 'hot' => false],
                ['n' => (string) $this->meRequestsInFlight($employee, $features, $tenant), 'label' => 'requests in flight', 'hot' => false],
                ['n' => (string) $unreadLessons, 'label' => 'unread lessons', 'hot' => false],
            ],
            'queue' => $this->meQueueRows($employee, $features, $tenant, $blankDays, $unreadLessons),
            'queueMeta' => [
                'title' => 'Your obligations',
                'done_title' => 'All clear',
                'done_body' => 'Nothing outstanding this week.',
            ],
            'secondary' => $this->meSecondaryRows($employee, $features, $tenant),
            'secondaryMeta' => [
                'title' => 'Your open requests',
                'done_title' => 'Nothing in flight',
                'done_body' => "You don't have any open leave or claim requests.",
                'link' => null,
            ],
            'railCards' => [
                ['id' => 'around', 'title' => 'Around you', 'rows' => $this->aroundRows($employee)],
                ['id' => 'news', 'title' => 'Announcements', 'rows' => $this->newsRows()],
            ],
        ];
    }

    /**
     * "What is waiting on me, and what is quietly rotting?" — the viewer's real
     * verify+approve queue (leave, claims), merged from the old manager/management/hr
     * dashboards and distinguished per-row by stage badge, plus StuckRequests as the
     * "reaching nobody" list.
     */
    private function companyScopeData(Request $request): array
    {
        $tenant = app(CurrentTenant::class)->get();

        $queue = $this->companyQueueRows($request);
        $secondary = $this->stuckRows();

        $lateTimesheets = app(TimesheetCompliance::class)
            ->roster($tenant, now()->startOfWeek())
            ->where('status', 'late')
            ->count();
        $headcount = $tenant->employees()->active()->count();

        return [
            'head' => $this->companyHead($queue->count()),
            'chips' => [
                ['n' => (string) $queue->count(), 'label' => 'waiting on you', 'hot' => $queue->count() > 0],
                ['n' => (string) $secondary->count(), 'label' => 'reaching nobody', 'hot' => $secondary->count() > 0],
                ['n' => (string) $lateTimesheets, 'label' => 'timesheets past lock', 'hot' => false],
                ['n' => (string) $headcount, 'label' => 'active headcount', 'hot' => false],
            ],
            'queue' => $queue,
            'queueMeta' => [
                'title' => 'Waiting on you',
                'done_title' => 'All caught up',
                'done_body' => 'Nothing needs your verification or approval right now.',
            ],
            'secondary' => $secondary,
            'secondaryMeta' => [
                'title' => 'Reaching nobody',
                'done_title' => 'Nothing stuck',
                'done_body' => 'Every submitted request has someone to verify it.',
                'link' => ['label' => 'Open Employee Directory', 'url' => route('app.screen', 'directory')],
            ],
            'railCards' => [
                ['id' => 'rot', 'title' => 'Quietly rotting', 'rows' => $this->rotRows()],
                ['id' => 'pop', 'title' => 'Headcount', 'rows' => $this->popRows($tenant)],
                ['id' => 'news', 'title' => 'Announcements', 'rows' => $this->newsRows()],
            ],
        ];
    }

    /** "Good afternoon, {firstName}." greeting + today's date and clock state. */
    private function meHead(?Employee $employee): array
    {
        $hour = (int) now()->hour;
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        $name = trim((string) ($employee->display_name ?? ''));
        $firstName = $name === '' ? '' : (string) Str::of($name)->squish()->explode(' ')->first();
        $h1 = $firstName === '' ? "{$greeting}." : "{$greeting}, {$firstName}.";

        // Prefer the still-open punch: an overnight shift's open record is dated
        // yesterday, so an onDate() lookup greeted someone mid-shift with "not clocked
        // in yet". Falls back to today's row so a closed day still reports its times.
        $today = $employee?->attendanceRecords()->openPunch(now())->first()
            ?? $employee?->attendanceRecords()->onDate(now())->first();
        $clockState = match (true) {
            $today === null || ! $today->clock_in => 'not clocked in yet',
            (bool) $today->clock_out => 'clocked out',
            default => 'clocked in at '.$today->clock_in,
        };

        return ['h1' => $h1, 'sub' => now()->format('l, j F Y')." · {$clockState}."];
    }

    /**
     * "Four requests are waiting on you." — a live sentence built from the queue
     * count, with number words for 1–9 and digits above (per copy convention).
     */
    private function companyHead(int $waiting): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if ($waiting === 0) {
            $h1 = 'Nothing is waiting on you.';
        } else {
            $number = $waiting <= 9 ? $this->numberWord($waiting) : (string) $waiting;
            $verb = $waiting === 1 ? 'is' : 'are';
            $h1 = "{$number} ".Str::plural('request', $waiting)." {$verb} waiting on you.";
        }

        return ['h1' => $h1, 'sub' => ($tenant?->name ?? 'Your company').' · '.now()->format('l, j F Y').'.'];
    }

    private function numberWord(int $n): string
    {
        return ['0' => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine'][$n] ?? (string) $n;
    }

    /** Trim a float to its shortest useful string: 12.0 → "12", 12.5 → "12.5". */
    private function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.') ?: '0';
    }

    /** Weekdays (Mon–Fri) this week not yet summing to 100% for the viewer. */
    private function meTimesheetBlankDays(?Employee $employee, FeatureManager $features, ?Tenant $tenant): int
    {
        if (! $employee || ! $features->screenAllowed($tenant, 'timesheets')) {
            return 0;
        }

        $start = now()->startOfWeek();
        $sheet = Timesheet::with('entries')->where('employee_id', $employee->id)->forWeek($start)->first();

        $byDay = [];
        foreach ($sheet?->entries ?? [] as $entry) {
            $day = $entry->entry_date->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + (float) $entry->percentage;
        }

        $blank = 0;
        for ($i = 0; $i < 5; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            if (($byDay[$day] ?? 0.0) < 100.0 - 0.01) {
                $blank++;
            }
        }

        return $blank;
    }

    /** How many unread Knowledge Bank lessons exist for the viewer (2 queries, no N+1). */
    private function meUnreadLessonCount(?Employee $employee, FeatureManager $features, ?Tenant $tenant): int
    {
        if (! $employee || ! $features->screenAllowed($tenant, 'knowledge-bank')) {
            return 0;
        }

        $readIds = KnowledgeRead::where('employee_id', $employee->id)->pluck('entry_id');

        return KnowledgeEntry::whereNotIn('id', $readIds)->count();
    }

    /** The viewer's own leave + claim requests still in flight (submitted or verified). */
    private function meRequestsInFlight(?Employee $employee, FeatureManager $features, ?Tenant $tenant): int
    {
        if (! $employee) {
            return 0;
        }

        $count = 0;
        if ($features->screenAllowed($tenant, 'leave')) {
            $count += $employee->leaveRequests()->whereIn('status', ['submitted', 'verified'])->count();
        }
        if ($features->screenAllowed($tenant, 'claims')) {
            $count += $employee->claims()->whereIn('status', ['submitted', 'verified'])->count();
        }

        return $count;
    }

    /** The viewer's own obligation rows: incomplete timesheet, still clocked in, unread lessons. */
    private function meQueueRows(?Employee $employee, FeatureManager $features, ?Tenant $tenant, int $blankDays, int $unreadLessons): Collection
    {
        $rows = collect();
        $today = now();

        if ($employee && $blankDays > 0 && $features->screenAllowed($tenant, 'timesheets')) {
            $rows->push([
                'month' => $today->format('M'), 'day' => $today->format('d'),
                'kind' => 'Timesheet', 'stage' => 'waiting', 'label' => null,
                'title' => "This week's timesheet is incomplete",
                'sub' => $blankDays.' '.Str::plural('day', $blankDays).' left blank',
                'body' => "Unfilled days don't lock automatically — fill them before the week closes, or your compliance record shows late.",
                'actions' => [['label' => 'Fill timesheet', 'url' => route('app.screen', 'timesheets'), 'primary' => true]],
            ]);
        }

        // Same overnight case as meHead(): without the open-punch lookup this reminder
        // disappeared at midnight for exactly the person still on the clock.
        $attendanceToday = $employee?->attendanceRecords()->openPunch($today)->first()
            ?? $employee?->attendanceRecords()->onDate($today)->first();
        if ($attendanceToday && $attendanceToday->clock_in && ! $attendanceToday->clock_out) {
            $rows->push([
                'month' => $today->format('M'), 'day' => $today->format('d'),
                'kind' => 'Attendance', 'stage' => 'waiting', 'label' => null,
                'title' => 'You are still clocked in',
                'sub' => 'Clocked in at '.$attendanceToday->clock_in,
                'body' => "Your clock stays open until you clock out — it keeps counting against today's hours.",
                'actions' => [['label' => 'Clock out', 'url' => route('app.screen', 'attendance'), 'primary' => true]],
            ]);
        }

        if ($unreadLessons > 0) {
            $rows->push([
                'month' => $today->format('M'), 'day' => $today->format('d'),
                'kind' => 'Knowledge Bank', 'stage' => 'waiting', 'label' => null,
                'title' => $unreadLessons.' unread '.Str::plural('lesson', $unreadLessons).' in the Knowledge Bank',
                'sub' => 'Shared by your colleagues',
                'body' => 'Lessons are short and optional to read, but the count keeps growing until you open them.',
                'actions' => [['label' => 'Open Knowledge Bank', 'url' => route('app.screen', 'knowledge-bank'), 'primary' => true]],
            ]);
        }

        return $rows->values();
    }

    /** The viewer's own open leave/claim requests, with their real verify/approve stage. */
    private function meSecondaryRows(?Employee $employee, FeatureManager $features, ?Tenant $tenant): Collection
    {
        if (! $employee) {
            return collect();
        }

        $rows = collect();

        if ($features->screenAllowed($tenant, 'leave')) {
            foreach ($employee->leaveRequests()->with('leaveType')->whereIn('status', ['submitted', 'verified'])->latest()->take(5)->get() as $r) {
                $stage = $r->status === 'submitted' ? 'step1' : 'step2';
                $rows->push([
                    'month' => $r->date_from?->format('M') ?? '', 'day' => $r->date_from?->format('d') ?? '',
                    'kind' => ($r->leaveType?->name ?? 'Leave').' leave', 'stage' => $stage,
                    'label' => $stage === 'step1' ? 'With your manager' : 'With management',
                    'title' => trim($r->date_from?->format('j M').'–'.$r->date_to?->format('j M').', '.$this->trimNumber((float) $r->days).' days'),
                    'sub' => 'Submitted '.($r->created_at?->format('j M') ?? ''),
                    'body' => RequestGuidance::for('leave', 'waiting'),
                    'actions' => [],
                ]);
            }
        }

        if ($features->screenAllowed($tenant, 'claims')) {
            foreach ($employee->claims()->whereIn('status', ['submitted', 'verified'])->latest()->take(5)->get() as $r) {
                $stage = $r->status === 'submitted' ? 'step1' : 'step2';
                $rows->push([
                    'month' => $r->date?->format('M') ?? '', 'day' => $r->date?->format('d') ?? '',
                    'kind' => ucfirst((string) $r->type).' claim', 'stage' => $stage,
                    'label' => $stage === 'step1' ? 'With your manager' : 'With management',
                    'title' => 'RM'.number_format((float) $r->amount, 2),
                    'sub' => 'Submitted '.($r->created_at?->format('j M') ?? ''),
                    'body' => RequestGuidance::for('claim', 'waiting'),
                    'actions' => [],
                ]);
            }
        }

        return $rows->values();
    }

    /**
     * Colleagues on approved leave, next 7 days, for the "Around you" rail card.
     * Rail rows are a flat, DIFFERENT shape from the pinned-queue ROW ARRAY — see
     * partials.dash.rail-card's doc block (title/sub/meta/initials/color/tag/url).
     */
    private function aroundRows(?Employee $employee): array
    {
        return $this->teamOnLeaveSoon($employee)->map(fn (LeaveRequest $r) => [
            'title' => $r->employee?->display_name ?? 'Someone',
            'sub' => trim($r->date_from->format('j M').'–'.$r->date_to->format('j M')),
            'meta' => ($r->leaveType?->name ?? 'Leave').' leave',
            'initials' => $r->employee?->initials ?? '–',
            'color' => $r->employee?->avatar_color ?? config('amanahku.avatar_color'),
        ])->all();
    }

    /**
     * Recent announcements, shared by both scopes' "news" rail card (flat rail-row
     * shape), merged with upcoming External TOT events tagged separately. An External
     * TOT row drops off the moment its event_date passes — it's an invite, not a
     * record, and a finished workshop lingering as a "brief" is stale noise once
     * nobody can register anymore. Gated behind the `tot` screen so a tenant with
     * the module off never gets a row linking to a screen it can't open (same rule
     * companyQueueRows() already follows for QUEUE_SOURCES).
     */
    private function newsRows(): array
    {
        $rows = Announcement::orderByDesc('date')->take(5)->get()->map(fn (Announcement $a) => [
            'title' => (string) ($a->title ?? ''),
            'sub' => (string) ($a->body ?? ''),
            'meta' => $a->date?->format('j M') ?? '',
            'tag' => 'News',
            '_sort' => $a->date,
        ]);

        $tenant = app(CurrentTenant::class)->get();
        if (app(FeatureManager::class)->screenAllowed($tenant, 'tot')) {
            $rows = $rows->concat(
                ExternalTotEvent::where('event_date', '>=', now()->toDateString())
                    ->orderByDesc('event_date')->take(5)->get()
                    ->map(fn (ExternalTotEvent $e) => [
                        'title' => $e->title,
                        'sub' => collect([$e->host, $e->event_date->format('j M')])->filter()->implode(' · '),
                        'meta' => $e->event_date->format('j M'),
                        'tag' => 'External TOT',
                        'url' => route('app.screen', 'tot').'?tab=external',
                        '_sort' => $e->event_date,
                    ])
            );
        }

        return $rows->sortByDesc('_sort')->take(5)->map(fn (array $r) => Arr::except($r, '_sort'))->values()->all();
    }

    /**
     * The viewer's real verify+approve queue across leave and claims, reusing the
     * exact authorisation in RoutesApprovalsByReportingLine::scopeToVerify/
     * scopeToApprove — only the OUTPUT shape changes here, not who is allowed to see
     * what. A request type whose module is off is dropped entirely (its row would
     * link to a screen the tenant cannot open).
     */
    private function companyQueueRows(Request $request): Collection
    {
        $features = app(FeatureManager::class);
        $tenant = app(CurrentTenant::class)->get();

        $sources = array_filter(self::QUEUE_SOURCES, fn (array $s) => $features->screenAllowed($tenant, $s[2]));

        $rows = collect();
        foreach ($sources as [$type, $label, $screen]) {
            $make = fn () => $type === 'leave'
                ? LeaveRequest::with(['employee', 'leaveType'])
                : Claim::with('employee');

            foreach ($this->scopeToVerify($make(), $request)->latest()->get() as $r) {
                $rows->push($this->companyRow($type, $screen, 'verify', $r));
            }
            foreach ($this->scopeToApprove($make(), $request)->latest()->get() as $r) {
                $rows->push($this->companyRow($type, $screen, 'approve', $r));
            }
        }

        return $rows->values();
    }

    /** One verify/approve queue row, in the shared ROW ARRAY shape. */
    private function companyRow(string $type, string $screen, string $stage, Model $r): array
    {
        $employee = $r->employee;
        $date = $type === 'leave' ? $r->date_from : $r->date;
        $waitingDays = $r->created_at ? (int) $r->created_at->diffInDays(now()) : 0;

        $title = $type === 'leave'
            ? trim(($employee?->name ?? 'Someone').' · '.$r->date_from?->format('j M').'–'.$r->date_to?->format('j M').', '.$this->trimNumber((float) $r->days).' days')
            : trim(($employee?->name ?? 'Someone').' · RM'.number_format((float) $r->amount, 2).' · '.ucfirst((string) $r->type));

        return [
            'month' => $date?->format('M') ?? '',
            'day' => $date?->format('d') ?? '',
            'kind' => $type === 'leave' ? (($r->leaveType?->name ?? 'Leave').' leave') : 'Claim',
            'stage' => $stage,
            'label' => null,
            'title' => $title,
            'sub' => 'Submitted '.($r->created_at?->format('j M') ?? '').' · '.$waitingDays.' '.Str::plural('day', $waitingDays).' waiting',
            'body' => RequestGuidance::for($type, $stage),
            'actions' => [
                ['label' => ucfirst($stage), 'url' => route('app.screen', $screen), 'primary' => true],
            ],
        ];
    }

    /**
     * Verified leave/claim requests that have sat waiting for final approval for 5+
     * days — nobody has actioned them, but they no longer show up as "new" either.
     * Flat rail-row shape (informational — this is a rail card, not the action queue).
     */
    private function rotRows(): array
    {
        $features = app(FeatureManager::class);
        $tenant = app(CurrentTenant::class)->get();
        $cutoff = now()->subDays(5);

        $rows = collect();
        if ($features->screenAllowed($tenant, 'leave')) {
            foreach (LeaveRequest::with(['employee', 'leaveType'])->where('status', 'verified')->where('verified_at', '<=', $cutoff)->latest('verified_at')->take(5)->get() as $r) {
                $rows->push($this->rotRow('Leave', $r->employee, $r->created_at));
            }
        }
        if ($features->screenAllowed($tenant, 'claims')) {
            foreach (Claim::with('employee')->where('status', 'verified')->where('verified_at', '<=', $cutoff)->latest('verified_at')->take(5)->get() as $r) {
                $rows->push($this->rotRow('Claim', $r->employee, $r->created_at));
            }
        }

        return $rows->take(5)->values()->all();
    }

    private function rotRow(string $tag, ?Employee $employee, ?CarbonInterface $since): array
    {
        $days = $since ? (int) $since->diffInDays(now()) : 0;

        return [
            'title' => $employee?->name ?? 'Someone',
            'sub' => $days.' '.Str::plural('day', $days).' waiting on final approval',
            'meta' => $tag,
            'initials' => $employee?->initials ?? '–',
            'color' => $employee?->avatar_color ?? config('amanahku.avatar_color'),
        ];
    }

    /** A couple of live headcount figures for the "Headcount" rail card (flat rail-row shape). */
    private function popRows(?Tenant $tenant): array
    {
        return [
            [
                'title' => 'Active headcount',
                'meta' => (string) ($tenant?->employees()->active()->count() ?? 0),
                'tag' => 'Live',
            ],
            [
                'title' => 'On leave today',
                'meta' => (string) Employee::active()->where('status', 'on_leave')->count(),
                'tag' => 'Live',
            ],
        ];
    }

    /** Submitted leave/claim requests whose submitter has no reporting-line superior. */
    /**
     * Stuck requests, one row per PERSON rather than per request.
     *
     * The cause is always the same — no reporting-line superior on the org chart — so
     * a person with a stuck leave request and a stuck claim produced two rows with an
     * identical name, age and fix, which read as a duplicate rather than as two items.
     * One row per person, listing what of theirs is stranded, and the single action
     * that clears all of it at once.
     */
    private function stuckRows(): Collection
    {
        return app(StuckRequests::class)->forCurrentTenant()
            ->groupBy(fn (array $s) => $s['employeeId'] ?? $s['employee'])
            ->map(function (Collection $forPerson) {
                // The oldest request drives the date and the age: it is the one that has
                // been ignored longest, and it is what makes the row urgent.
                $oldest = $forPerson->sortByDesc('ageDays')->first();
                $date = $oldest['since'];
                $days = $oldest['ageDays'];

                $kinds = $forPerson->pluck('type')
                    ->countBy()
                    ->map(fn (int $n, string $type) => $n > 1 ? "{$n} × {$type}" : $type)
                    ->values()->implode(' · ');

                $count = $forPerson->count();
                $waited = $days !== null
                    ? ($count > 1 ? 'oldest ' : '').$days.' '.Str::plural('day', $days).' unrouted'
                    : 'unrouted';

                return [
                    'month' => $date?->format('M') ?? '', 'day' => $date?->format('d') ?? '',
                    'kind' => $kinds, 'stage' => 'stuck', 'label' => 'No superior assigned',
                    'title' => $oldest['employee'],
                    'sub' => $count.' '.Str::plural('request', $count).' · '.$waited,
                    'body' => RequestGuidance::for('stuck', 'stuck'),
                    'actions' => [
                        ['label' => 'Assign a superior', 'url' => route('app.screen', 'directory'), 'primary' => true],
                    ],
                    // Sort key only — stripped below so it never reaches the view.
                    '_age' => $days ?? 0,
                ];
            })
            ->sortByDesc('_age')
            ->map(fn (array $r) => Arr::except($r, '_age'))
            ->values();
    }

    /** Colleagues on approved leave from today through the next 7 days (bounded). */
    private function teamOnLeaveSoon(?Employee $employee): Collection
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(7);

        return LeaveRequest::with(['employee:id,name,nickname,initials,avatar_color', 'leaveType:id,name'])
            ->where('status', 'approved')
            ->when($employee, fn ($q) => $q->where('employee_id', '!=', $employee->id))
            ->whereHas('employee', fn ($q) => $q->active())
            ->whereDate('date_from', '<=', $horizon->toDateString())
            ->whereDate('date_to', '>=', $today->toDateString())
            ->orderBy('date_from')
            ->take(8)
            ->get();
    }

    /** Active colleagues with a birthday this month, sorted by day (real DOB, viewer excluded). */
    private function birthdaysThisMonth(?Employee $employee): Collection
    {
        return Employee::active()
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', now()->month)
            ->when($employee, fn ($q) => $q->where('id', '!=', $employee->id))
            ->get(['id', 'name', 'nickname', 'initials', 'avatar_color', 'date_of_birth'])
            ->sortBy(fn (Employee $e) => (int) $e->date_of_birth->format('d'))
            ->values();
    }

    private function departmentCapacity(): Collection
    {
        return Department::withCount(['employees' => fn ($q) => $q->whereNull('archived_at')])->orderByDesc('employees_count')->get()->map(function ($d) {
            $cap = min(50 + $d->employees_count * 11, 99);

            return ['name' => $d->name, 'head' => $d->employees_count, 'cap' => $cap, 'color' => $cap >= 90 ? 'red' : ($cap >= 80 ? 'amber' : 'green')];
        });
    }

    private function managerStats(): array
    {
        $total = Employee::active()->count();
        $present = Employee::active()->where('status', 'active')->count();
        // Exclude cards owned by an archived person (their open work is reassigned to their
        // manager on archive; this also cleans any pre-existing rows). Unassigned cards
        // (null owner) are legitimate open work and stay counted.
        $openWork = WorkItem::where('status', '!=', 'done')
            ->where(fn ($q) => $q->whereNull('employee_id')->orWhereHas('employee', fn ($e) => $e->active()))
            ->count();

        $stats = [
            ['k' => 'Team present', 'v' => "$present/$total", 'c' => 'var(--ink)'],
            ['k' => 'Open work items', 'v' => (string) $openWork, 'c' => $openWork > 10 ? 'var(--amber)' : 'var(--ink)'],
        ];

        // Avg KPI is part of the Performance module — drop the card entirely when that
        // module is off, so the manager dashboard tracks the same 'kpi' screen toggle that
        // already hides the KPI screen, its nav entry, and the profile KPI widgets ($perfEnabled).
        if (app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), 'kpi')) {
            $avgKpi = (int) round((float) (Employee::active()->avg('kpi_pct') ?? 0));
            $stats[] = ['k' => 'Avg KPI', 'v' => "$avgKpi%", 'c' => 'var(--ink)'];
        }

        return $stats;
    }

    /**
     * A manager's direct reports for the dashboard Team-status table. The `workload` /
     * `workload_label` shown per row are the Employee model's LIVE accessors (open work-item
     * count), not the frozen seed column; `withCount` loads that count up front so the accessor
     * reads it without an N+1. Empty collection when the viewer has no employee record, so a
     * super-admin previewing the manager persona never sees strangers' rows.
     *
     * @return Collection<int, Employee>
     */
    private function managerTeam(?Employee $employee): Collection
    {
        if (! $employee) {
            return collect();
        }

        // Eager-load ONLY today's attendance row per member so the Team-status "Today"
        // cell shows real clock in/out (not a leave-vs-not guess) without an N+1.
        return Employee::active()
            ->where('reports_to_id', $employee->id)
            ->with(['department', 'attendanceRecords' => fn ($q) => $q->onDate(now())])
            ->withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=', 'done')])
            ->orderByDesc('open_items_count')
            ->get();
    }

    /**
     * Header counts for the manager dashboard subtitle: how many direct reports, how many of
     * them are on leave today, and how many still owe THIS week's timesheet. Zeros when the
     * viewer has no employee record. Reads the same reporting line as managerTeam() so the
     * heading and the Team-status table can never disagree.
     *
     * @return array{direct_reports:int, on_leave:int, timesheets_outstanding:int}
     */
    private function managerHeadingStats(?Employee $employee): array
    {
        if (! $employee) {
            return ['direct_reports' => 0, 'on_leave' => 0, 'timesheets_outstanding' => 0];
        }

        $reports = Employee::active()->where('reports_to_id', $employee->id)->get(['id', 'status']);
        $pendingIds = app(WorkforceInsights::class)->pendingTimesheets()->pluck('id');

        return [
            'direct_reports' => $reports->count(),
            'on_leave' => $reports->where('status', 'on_leave')->count(),
            'timesheets_outstanding' => $reports->pluck('id')->intersect($pendingIds)->count(),
        ];
    }

    private function hrStats(): array
    {
        // Real weekly timesheet compliance — replaces the old hardcoded '87%' seed copy.
        $roster = app(TimesheetCompliance::class)->roster(app(CurrentTenant::class)->get(), now()->startOfWeek());
        $tsPct = $roster->isEmpty()
            ? 100
            : (int) round($roster->where('status', 'done')->count() / $roster->count() * 100);

        // The count is employees.status, so the tile stays. Its subtitle promises a
        // confirmation workflow that only the Probation module provides, so the copy
        // (and its amber "needs action" tone) follows that module's gate.
        $probation = app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), 'probation');

        return [
            ['k' => 'Headcount', 'v' => Employee::active()->count(), 'sub' => 'across all branches', 'subc' => 'var(--muted)'],
            ['k' => 'On probation', 'v' => Employee::active()->where('status', 'probation')->count(), 'sub' => $probation ? 'confirmations pending' : 'of active headcount', 'subc' => $probation ? 'var(--amber)' : 'var(--muted)'],
            ['k' => 'On leave today', 'v' => Employee::active()->where('status', 'on_leave')->count(), 'sub' => 'see leave calendar', 'subc' => 'var(--muted)'],
            ['k' => 'Timesheets filled', 'v' => $tsPct.'%', 'sub' => 'this week', 'subc' => $tsPct >= 80 ? 'var(--success)' : 'var(--amber)'],
        ];
    }

    private function workloadData(): array
    {
        return [
            // Bars ordered by real load (heaviest first); colour/label come from the live
            // workload accessors, fed by the eager open_items_count so there's no N+1.
            'bars' => Employee::active()
                ->withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=', 'done')])
                ->orderByDesc('open_items_count')->get()->map(fn ($e) => [
                    'name' => $e->name, 'initials' => $e->initials, 'avatar' => $e->avatar_color,
                    'color' => $e->workload, 'pct' => min((int) ($e->kpi_pct + 20), 130),
                    'capped' => min((int) ($e->kpi_pct + 20), 100), 'label' => $e->workload_label,
                ]),
            'recs' => app(WorkforceInsights::class)->recommendations(),
        ];
    }

    private function achievementsData(string $role): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $privileged = in_array($role, ['manager', 'management', 'hr'], true);

        // Bounded to the 50 most-recent — the feed used to hydrate the whole table every
        // load (AK-PERF-03). Backed by the (tenant_id, date) index. totalCount below comes
        // from a COUNT, not the bounded collection, so the headline stays accurate.
        // whereHas active() throughout so an archived recipient's recognition drops from the
        // feed and the headline totals — recognition follows current staff.
        $feed = Achievement::with('employee')->whereHas('employee', fn ($q) => $q->active())
            ->orderByDesc('date')->orderByDesc('id')->take(50)->get();

        // Points leaderboard — ordering + the has-achievements filter pushed into SQL
        // (AK-PERF-03); no more hydrate-all-employees-then-sort-in-PHP. The aggregate
        // sub-queries are explicitly tenant-scoped (belt-and-suspenders: employee_id is
        // globally unique, but we never want a sub-query to escape the active tenant).
        $scopeTenant = fn ($q) => $q->where('tenant_id', $tenantId);
        $leaders = Employee::active()
            ->whereHas('achievements', $scopeTenant)
            ->withSum(['achievements as recognition_points' => $scopeTenant], 'points')
            ->withCount(['achievements as achievements_count' => $scopeTenant])
            ->orderByDesc('recognition_points')
            ->take(5)
            ->get();

        return [
            'feed' => $feed,
            'leaders' => $leaders,
            'totalPoints' => (int) Achievement::whereHas('employee', fn ($q) => $q->active())->sum('points'),
            'totalCount' => Achievement::whereHas('employee', fn ($q) => $q->active())->count(),
            'thisMonth' => Achievement::whereHas('employee', fn ($q) => $q->active())->whereDate('date', '>=', now()->startOfMonth()->toDateString())->count(),
            // Recipient picker is only used by the privileged-only recognition form.
            'recipients' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name', 'nickname', 'initials', 'avatar_color']) : collect(),
        ];
    }

    private function reviewsData(?Employee $employee, string $role): array
    {
        $mine = $employee
            ? PerformanceReview::with('reviewer')->where('employee_id', $employee->id)->orderByDesc('cycle')->get()
            : collect();

        // Other employees' review content is confidential — only load the team view for
        // privileged roles, never just hide it at the template layer.
        $teamReviews = in_array($role, ['manager', 'management', 'hr'], true)
            ? PerformanceReview::with(['employee', 'reviewer'])
                ->whereHas('employee', fn ($q) => $q->active())
                ->whereIn('status', ['in_progress', 'completed'])
                ->latest('updated_at')->take(8)->get()
            : collect();

        return [
            'current' => $mine->first(fn ($r) => in_array($r->status, ['scheduled', 'in_progress'], true)),
            'latest' => $mine->first(fn ($r) => in_array($r->status, ['completed', 'acknowledged'], true)),
            'history' => $mine,
            'teamReviews' => $teamReviews,
        ];
    }
}
