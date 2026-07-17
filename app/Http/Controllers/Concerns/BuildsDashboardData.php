<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\SetupController;
use App\Models\Achievement;
use App\Models\Announcement;
use App\Models\Claim;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OnboardingProfile;
use App\Models\OvertimeRequest;
use App\Models\PerformanceReview;
use App\Models\ProbationReview;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use App\Support\Amanahku;
use App\Support\StuckRequests;
use App\Support\WorkforceInsights;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Dashboard, achievements and reviews screen data for AppController::screen().
 * Split out of AppController purely for file size — every method still runs on
 * the controller instance ($this), so cross-trait calls keep working.
 */
trait BuildsDashboardData
{
    /**
     * Live workforce figures for the dashboard heading (management & hr personas only).
     * Replaces the old hardcoded "186 headcount" seed copy with real, tenant-scoped counts.
     * Other personas need no stats and get an empty array. "On probation" mirrors the source
     * used by the HR snapshot tiles (Employee status), so the header and tiles never disagree.
     */
    private function dashStats(Tenant $tenant, string $persona, ?Employee $employee = null): array
    {
        if ($persona === 'manager') {
            return $this->managerHeadingStats($employee);
        }

        if (! in_array($persona, ['management', 'hr'], true)) {
            return [];
        }

        $headcount = $tenant->employees()->active()->count();

        if ($persona === 'management') {
            return ['headcount' => $headcount, 'company' => $tenant->name];
        }

        return [
            'headcount' => $headcount,
            'on_probation' => Employee::active()->where('status', 'probation')->count(),
            'confirmations_due' => ProbationReview::where('status', 'active')
                ->whereHas('employee', fn ($q) => $q->active())
                ->whereBetween('end_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    private function dashboardData(string $persona, ?Employee $employee): array
    {
        return match ($persona) {
            'manager' => [
                'team' => $this->managerTeam($employee),
                'mgrStats' => $this->managerStats(),
                'recs' => app(WorkforceInsights::class)->recommendations(),
            ],
            'management' => [
                'deptCap' => $this->departmentCapacity(),
                'risks' => Amanahku::operationalRisks(),
                'stuckRequests' => app(StuckRequests::class)->forCurrentTenant(),
            ],
            'hr' => [
                'hrStats' => $this->hrStats(),
                'onboarding' => OnboardingProfile::with('employee')->whereHas('employee', fn ($q) => $q->active())->get(),
                'announcements' => Announcement::orderByDesc('date')->take(5)->get(),
                'setupProgress' => app(SetupController::class)->summary(),
                'stuckRequests' => app(StuckRequests::class)->forCurrentTenant(),
            ],
            default => [
                'workItems' => $employee?->workItems()->whereIn('status', ['todo', 'prog', 'review'])->take(4)->get() ?? collect(),
                'announcements' => Announcement::orderByDesc('date')->take(3)->get(),
                'achievements' => Achievement::with('employee')->whereHas('employee', fn ($q) => $q->active())
                    ->orderByDesc('date')->orderByDesc('id')->take(2)->get(),
                'pendingRequests' => $employee?->leaveRequests()->with('leaveType')->latest()->take(3)->get() ?? collect(),
                'todayAttendance' => $employee?->attendanceRecords()->onDate(now())->first(),
                ...$this->employeeDashPeople($employee),
            ],
        };
    }

    /**
     * People-centric widgets for the employee dashboard: colleagues on approved leave
     * over the next week, this month's birthdays (real DOB), and today's birthdays
     * flagged for the greeting banner + one-tap wish. All exclude the viewer.
     *
     * @return array{onLeave: Collection, birthdays: Collection, bdayToday: Collection}
     */
    private function employeeDashPeople(?Employee $employee): array
    {
        $birthdays = $this->birthdaysThisMonth($employee);
        $todayKey = now()->format('m-d');

        return [
            'onLeave' => $this->teamOnLeaveSoon($employee),
            'birthdays' => $birthdays,
            'bdayToday' => $birthdays->filter(
                fn (Employee $e) => $e->date_of_birth?->format('m-d') === $todayKey
            )->values(),
        ];
    }

    /** Colleagues on approved leave from today through the next 7 days (bounded). */
    private function teamOnLeaveSoon(?Employee $employee): Collection
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(7);

        return LeaveRequest::with(['employee:id,name,initials,avatar_color', 'leaveType:id,name'])
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
            ->get(['id', 'name', 'initials', 'avatar_color', 'date_of_birth'])
            ->sortBy(fn (Employee $e) => (int) $e->date_of_birth->format('d'))
            ->values();
    }

    /**
     * Everything routed to the CURRENT user for action across leave, claims and overtime:
     * their VERIFY queue (as someone's manager on the org chart) plus their APPROVE queue
     * (management only — the scope self-empties otherwise). Deliberately keyed off the real
     * request, not the previewed persona, because these are the real user's obligations.
     * Bounded to a short actionable list for the dashboard.
     *
     * @return array{actionNeeded: Collection, actionNeededTotal: int}
     */
    private function pendingActions(Request $request): array
    {
        // Fresh builder per call — a Builder is mutable, so the same instance cannot be
        // fed to both scopeToVerify and scopeToApprove without stacking constraints.
        $sources = [
            ['Leave', 'leave', fn () => LeaveRequest::with(['employee', 'leaveType'])],
            ['Claim', 'claims', fn () => Claim::with(['employee', 'verifiedBy'])],
            ['Overtime', 'overtime', fn () => OvertimeRequest::with(['employee', 'verifiedBy'])],
        ];

        $items = collect();
        foreach ($sources as [$label, $screen, $make]) {
            foreach ($this->scopeToVerify($make(), $request)->latest()->get() as $r) {
                $items->push($this->actionItem($label, 'verify', $screen, $r));
            }
            foreach ($this->scopeToApprove($make(), $request)->latest()->get() as $r) {
                $items->push($this->actionItem($label, 'approve', $screen, $r));
            }
        }

        return [
            'actionNeeded' => $items->take(8)->values(),
            'actionNeededTotal' => $items->count(),
        ];
    }

    /**
     * Flat display row for one pending action.
     *
     * @return array<string, mixed>
     */
    private function actionItem(string $label, string $stage, string $screen, Model $record): array
    {
        return [
            'label' => $label,
            'stage' => $stage,
            'who' => $record->employee?->name ?? 'Someone',
            'initials' => $record->employee?->initials ?? '–',
            'color' => $record->employee?->avatar_color ?? config('amanahku.avatar_color'),
            'url' => route('app.screen', $screen),
        ];
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

        return [
            ['k' => 'Headcount', 'v' => Employee::active()->count(), 'sub' => 'across all branches', 'subc' => 'var(--muted)'],
            ['k' => 'On probation', 'v' => Employee::active()->where('status', 'probation')->count(), 'sub' => 'confirmations pending', 'subc' => 'var(--amber)'],
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
            'recipients' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color']) : collect(),
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
