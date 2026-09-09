<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Attendance\HolidayEve;
use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\Claim;
use App\Models\CompanyEvent;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeRead;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Support\DashboardPrefs;
use App\Support\EasterEggBank;
use App\Support\GreetingBank;
use App\Support\RequestGuidance;
use App\Support\StuckRequests;
use App\Support\WorkforceInsights;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Shared dashboard row builders, plus achievements and reviews screen data, for
 * AppController::screen(). Split out of AppController purely for file size —
 * every method still runs on the controller instance ($this), so cross-trait
 * calls keep working.
 *
 * The dashboard's own entry point lives in BuildsDashboardWidgets, which composes
 * the row builders here into per-widget payloads.
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
     * CR-33: rotating, context-aware greeting. "Keep it plain" (or no employee record)
     * keeps the original static "Good morning/afternoon/evening, {name}." behaviour;
     * otherwise a line is picked from App\Support\GreetingBank based on which triggers
     * are active right now, and the picked id is remembered in session so the next
     * pageview doesn't repeat it.
     */
    private function meHead(Request $request, ?Employee $employee): array
    {
        $now = now();
        $name = trim((string) ($employee->display_name ?? ''));
        $firstName = $name === '' ? '' : (string) Str::of($name)->squish()->explode(' ')->first();

        // Prefer the still-open punch: an overnight shift's open record is dated
        // yesterday, so an onDate() lookup greeted someone mid-shift with "not clocked
        // in yet". Falls back to today's row so a closed day still reports its times.
        $today = $employee?->attendanceRecords()->openPunch($now)->first()
            ?? $employee?->attendanceRecords()->onDate($now)->first();
        $clockState = match (true) {
            $today === null || ! $today->clock_in => 'not clocked in yet',
            (bool) $today->clock_out => 'clocked out',
            default => 'clocked in at '.$today->clock_in,
        };
        $sub = $now->format('l, j F Y')." · {$clockState}.";

        $plain = DashboardPrefs::forUser($request->user()?->dashboard_prefs)['plain'];

        if ($plain || ! $employee) {
            return ['h1' => $this->plainGreeting($now, $firstName, 'en'), 'h1_ms' => $this->plainGreeting($now, $firstName, 'ms'), 'sub' => $sub];
        }

        $triggers = $this->activeGreetingTriggers($employee, $today, $now);

        // CR-33 "first load" markers for month_start / back_from_leave: stamped on every
        // non-plain load so a trigger that fired once for this browser session doesn't
        // fire again on the next reload of the same day/leave-return.
        session(['greeting.month_seen' => $now->format('Y-m'), 'greeting.dash_last_load' => $now->toDateString()]);

        $lastId = (string) session('greeting.last', '');
        $line = GreetingBank::pick($employee->tenant_id, $triggers, $lastId !== '' ? $lastId : null);

        if ($line === null) {
            return ['h1' => $this->plainGreeting($now, $firstName, 'en'), 'h1_ms' => $this->plainGreeting($now, $firstName, 'ms'), 'sub' => $sub];
        }

        session(['greeting.last' => (string) $line->id]);

        return [
            'h1' => $this->fillGreetingName($line->text_en, $firstName),
            'h1_ms' => $this->fillGreetingName($line->text_ms, $firstName),
            'sub' => $sub,
        ];
    }

    /** The original static "Good morning/afternoon/evening, {name}." fallback. */
    private function plainGreeting(CarbonInterface $now, string $firstName, string $lang): string
    {
        $hour = (int) $now->hour;
        $greeting = $lang === 'en'
            ? match (true) {
                $hour < 12 => 'Good morning',
                $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            }
        : match (true) {
            $hour < 12 => 'Selamat pagi',
            $hour < 18 => 'Selamat petang',
            default => 'Selamat malam',
        };

        return $firstName === '' ? "{$greeting}." : "{$greeting}, {$firstName}.";
    }

    /** Replace {name}, or cleanly strip it (and its leading ", ") when there is none. */
    private function fillGreetingName(string $text, string $firstName): string
    {
        if ($firstName !== '') {
            return str_replace('{name}', $firstName, $text);
        }

        return str_replace('{name}', '', str_replace(', {name}', '', $text));
    }

    /**
     * CR-31: one contextual aside under the greeting, at most one per load,
     * priority late_night (22:00+) > friday_late (Friday 17:00+) > holiday_eve.
     * "Keep it plain" computes and records nothing — a plain viewer never calls
     * EasterEggBank::showOnce() at all. Once shown, easter_egg_views keeps it
     * from showing again the same day (see EasterEggBank::showOnce()).
     *
     * @return array{kind: string, text_en: string, text_ms: string}|null
     */
    private function dashboardEgg(?Employee $employee, CarbonInterface $now, bool $plain): ?array
    {
        if ($plain || ! $employee) {
            return null;
        }

        $kind = match (true) {
            (int) $now->hour >= 22 => 'late_night',
            $now->isFriday() && (int) $now->hour >= 17 => 'friday_late',
            app(HolidayEve::class)->forDay($now) !== null => 'holiday_eve',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $egg = EasterEggBank::showOnce($employee->tenant_id, $employee->id, $kind, $now);

        if (! $egg) {
            return null;
        }

        // The late-night shortcut lands on Overtime only when that module is on for the
        // tenant; otherwise it points at the timesheet so it never 404s.
        $overtime = app(FeatureManager::class)->screenAllowed(Tenant::find($employee->tenant_id), 'overtime');

        return [
            'kind' => $kind,
            'text_en' => $egg->text_en,
            'text_ms' => $egg->text_ms,
            'shortcut' => $overtime ? '/app/overtime' : '/app/timesheets',
            'shortcut_en' => $overtime ? 'Log your hours as overtime?' : 'Log your hours on the timesheet?',
            'shortcut_ms' => $overtime ? 'Log jam kerja sebagai lebih masa?' : 'Log jam kerja dalam timesheet?',
        ];
    }

    /**
     * Real signals that decide which greeting bucket/trigger fires. Every active
     * trigger is passed to GreetingBank::pick(), which walks buckets in priority
     * order (personal, situation, day, time) and matches within the first
     * non-empty one.
     *
     * @return list<string>
     */
    private function activeGreetingTriggers(Employee $employee, ?Model $today, CarbonInterface $now): array
    {
        $triggers = [];

        $dob = $employee->date_of_birth;
        if ($dob !== null && $dob->month === $now->month && $dob->day === $now->day) {
            $triggers[] = 'birthday';
        }

        $joined = $employee->joined_at;
        if ($joined !== null && $joined->month === $now->month && $joined->day === $now->day
            && ! ($dob !== null && $dob->month === $now->month && $dob->day === $now->day)) {
            $triggers[] = 'anniversary';
        }

        // First load after an approved leave that ended before today, and no later
        // than the last recorded load caught it — session-marker "first load" pattern,
        // same one greeting.last already uses for no-immediate-repeat.
        $lastLoad = session('greeting.dash_last_load');
        if (LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('date_to', '<', $now->toDateString())
            ->when($lastLoad, fn ($q) => $q->where('date_to', '>=', $lastLoad))
            ->exists()
        ) {
            $triggers[] = 'back_from_leave';
        }

        if (app(HolidayEve::class)->forDay($now) !== null) {
            $triggers[] = 'holiday_eve';
        }

        if ($this->isLongWeekend($employee->tenant_id, $now)) {
            $triggers[] = 'long_weekend';
        }

        if ($now->day === 1 && session('greeting.month_seen') !== $now->format('Y-m')) {
            $triggers[] = 'month_start';
        }

        // "All clear": at least one open card AND none of them overdue. Without the
        // "has an open card" half, someone with an empty board trivially has "nothing
        // overdue" and this would fire on every quiet day, outranking day/time lines.
        $openCards = WorkItem::where('employee_id', $employee->id)
            ->where('status', '!=', 'done')
            ->whereNull('archived_at')
            ->whereNull('cancelled_at')
            ->where('type', '!=', 'event');

        $hasOpenCards = (clone $openCards)->exists();
        $hasOverdueCards = (clone $openCards)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now->toDateString())
            ->exists();

        if ($hasOpenCards && ! $hasOverdueCards) {
            $triggers[] = 'all_clear';
        }

        // Weather lines have no real signal wired up (no forecast source, no port) — the
        // flag exists so a later session can wire a real one behind it, but this session
        // never fires `rain` on its own. See docs/build/OPEN.md.

        $triggers[] = match (true) {
            $now->isMonday() => 'monday',
            $now->isWednesday() => 'wednesday',
            $now->isFriday() => 'friday',
            $now->isSaturday() => 'saturday',
            $now->isSunday() => 'weekend',
            default => null,
        };

        $triggers[] = match (true) {
            (int) $now->hour < 8 => 'early',
            (int) $now->hour < 12 => 'morning',
            (int) $now->hour < 18 => 'afternoon',
            (int) $now->hour < 22 => 'evening',
            default => 'late',
        };

        return array_values(array_filter($triggers));
    }

    /** A public holiday on the Friday before, or the Monday after, the coming weekend. */
    private function isLongWeekend(int $tenantId, CarbonInterface $now): bool
    {
        $saturday = in_array($now->dayOfWeekIso, [6, 7], true)
            ? $now->copy()->startOfWeek(CarbonInterface::SATURDAY)
            : $now->copy()->next(CarbonInterface::SATURDAY);

        $dates = [
            $saturday->copy()->subDay()->toDateString(),
            $saturday->copy()->addDays(2)->toDateString(),
        ];

        return PublicHoliday::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereDate('date', $dates[0])->orWhereDate('date', $dates[1]))
            ->exists();
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
                    'month' => $r->date_from->format('M'), 'day' => $r->date_from->format('d'),
                    'kind' => ($r->leaveType?->name ?? 'Leave').' leave', 'stage' => $stage,
                    'label' => $stage === 'step1' ? 'With your manager' : 'With management',
                    'title' => trim($r->date_from->format('j M').'–'.$r->date_to->format('j M').', '.$this->trimNumber((float) $r->days).' days'),
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
                    'month' => $r->date->format('M'), 'day' => $r->date->format('d'),
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
     * Recent announcements, shared by both scopes' "news" rail card (flat rail-row
     * shape), merged with upcoming external events tagged separately. An external
     * event row drops off the moment its event_date passes — it's an invite, not a
     * record, and a finished workshop lingering as a "brief" is stale noise once
     * nobody can register anymore. Gated behind the `events` screen so a tenant with
     * the module off never gets a row linking to a screen it can't open (same rule
     * companyQueueRows() already follows for QUEUE_SOURCES).
     */
    private function newsRows(?Employee $employee): array
    {
        $rows = Announcement::orderByDesc('date')->take(5)->get()->map(fn (Announcement $a) => [
            'title' => (string) ($a->title ?? ''),
            'sub' => (string) ($a->body ?? ''),
            'meta' => $a->date?->format('j M') ?? '',
            'tag' => 'News',
            '_sort' => $a->date,
        ]);

        $tenant = app(CurrentTenant::class)->get();
        if (app(FeatureManager::class)->screenAllowed($tenant, 'events')) {
            $rows = $rows->concat(
                CompanyEvent::whereNotNull('host')
                    ->where('event_date', '>=', now()->toDateString())
                    ->orderByDesc('event_date')->take(5)->get()
                    ->map(fn (CompanyEvent $e) => [
                        'title' => $e->title,
                        'sub' => collect([$e->host, $e->event_date->isoFormat('ddd, D MMM')])->filter()->implode(' · '),
                        // How long you have left, not what the calendar says — a date the
                        // reader has to subtract from today is a date they skim past.
                        'meta' => $this->daysAway($e->event_date),
                        'tag' => 'External event',
                        'flag' => $employee && in_array($employee->id, $e->taggedIds(), true) ? 'Required' : null,
                        'url' => route('app.screen', 'events'),
                        '_sort' => $e->event_date,
                    ])
            );
        }

        return $rows->sortByDesc('_sort')->take(5)->map(fn (array $r) => Arr::except($r, '_sort'))->values()->all();
    }

    /** "today" / "tomorrow" / "in 3 days" — the rail's right-hand meta for a dated event. */
    private function daysAway(CarbonInterface $date): string
    {
        $days = (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            default => "in {$days} days",
        };
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

    private function departmentCapacity(): Collection
    {
        return Department::withCount(['employees' => fn ($q) => $q->whereNull('archived_at')])->orderByDesc('employees_count')->get()->map(function ($d) {
            $cap = min(50 + $d->employees_count * 11, 99);

            return ['name' => $d->name, 'head' => $d->employees_count, 'cap' => $cap, 'color' => $cap >= 90 ? 'red' : ($cap >= 80 ? 'amber' : 'green')];
        });
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
