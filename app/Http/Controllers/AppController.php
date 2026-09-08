<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsDashboardData;
use App\Http\Controllers\Concerns\BuildsDashboardWidgets;
use App\Http\Controllers\Concerns\BuildsNav;
use App\Http\Controllers\Concerns\BuildsPeopleData;
use App\Http\Controllers\Concerns\BuildsSettingsData;
use App\Http\Controllers\Concerns\BuildsWorkData;
use App\Http\Controllers\Concerns\RoutesApprovalsByReportingLine;
use App\Http\Requests\UpdateDashboardPrefsRequest;
use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Services\FeatureManager;
use App\Support\Amanahku;
use App\Support\Changelog;
use App\Support\DashboardPrefs;
use App\Support\DashboardWidgets;
use App\Support\Permissions;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use App\Timesheet\DayRules;
use App\Timesheet\LockedDays;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workspace entry (branded login → tenant select → enter) and the shared
 * screen shell: authorises the requested screen, dispatches to the matching
 * data builder, and renders it inside the app chrome. The per-domain data
 * builders live in the Concerns\Builds* traits — split by screen family so
 * each file stays readable; they all run on this controller instance.
 */
class AppController extends Controller
{
    use BuildsDashboardData;
    use BuildsDashboardWidgets;
    use BuildsNav;
    use BuildsPeopleData;
    use BuildsSettingsData;
    use BuildsWorkData;
    use RoutesApprovalsByReportingLine;

    /**
     * Company-branded sign-in page (/login/{slug}). Renders the standard login form
     * dressed in the company's logo, colours and welcome message, and remembers the
     * intended workspace so a successful sign-in lands the member straight in it.
     * Unknown slugs 404 via route-model binding — no other company's data is exposed.
     */
    public function brandedLogin(Tenant $tenant): ViewContract|RedirectResponse
    {
        $request = request();
        $request->session()->put('intended_tenant', $tenant->slug);

        if ($request->user()) {
            return redirect()->route('tenant.select');
        }

        return view('auth.login', ['brandTenant' => $tenant]);
    }

    /** Post-login: pick a workspace from the user's real memberships. */
    public function tenantSelect(Request $request): ViewContract|RedirectResponse
    {
        // Honour a company-branded entry point: if the member arrived via /login/{slug}
        // and genuinely belongs to that company, drop them straight in (consumed once).
        // A non-member intent is silently ignored — they only ever see their own tenants.
        $intended = $request->session()->pull('intended_tenant');
        if ($intended) {
            $tenant = Tenant::where('slug', $intended)->first();
            if ($tenant && $request->user()->canAccessTenant($tenant)) {
                return redirect()->route('tenant.enter', $tenant);
            }
        }

        // A super-admin picks from every company (invisible observer access); everyone
        // else only ever sees the companies they hold a membership in.
        $tenants = $request->user()->isSuperAdmin()
            ? Tenant::query()
            : $request->user()->tenants();

        // A plain member of exactly one company skips the picker entirely — nothing to
        // choose. Super-admins still see the full picker, since their "membership" is
        // every tenant.
        if (! $request->user()->isSuperAdmin()) {
            $only = $tenants->get();
            if ($only->count() === 1) {
                return redirect()->route('tenant.enter', $only->first());
            }
        }

        return view('tenant.select', [
            'tenants' => $tenants
                ->withCount([
                    'branches',
                    'employees as active_employees_count' => fn ($q) => $q->whereNull('archived_at'),
                ])
                ->get(),
        ]);
    }

    /** Activate a tenant for the session and jump into the shell. */
    public function enterTenant(Tenant $tenant): RedirectResponse
    {
        abort_unless(request()->user()->canAccessTenant($tenant), 403);

        session([
            'current_tenant' => $tenant->id,
            'persona' => request()->user()->roleIn($tenant),
        ]);

        return redirect()->route('app.screen', 'dash');
    }

    /** Render an app screen inside the shared shell with tenant-scoped data. */
    public function screen(Request $request, string $screen = 'dash'): ViewContract
    {
        $tenant = app(CurrentTenant::class)->get();
        // Directors act as management for every screen gate, nav item and persona view —
        // the "Director" identity itself surfaces on the roles screen and the org chart, not
        // in what they can reach. Collapsing here means no blade `in_array($role, …)` gate
        // has to learn about the new role.
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        $employee = $request->attributes->get('employee');

        // Persona is a demo/role-preview toggle. A role may only preview personas on its
        // own whitelist (Amanahku::PERSONA_ACCESS) — downward/lateral, never upward. This
        // rejects e.g. a manager forcing `?persona=hr` to peek at HR's dashboard (AK-AUTHZ-02).
        // A plain employee's whitelist is just 'employee', so the same guard locks them in.
        if ($request->filled('persona')
            && in_array($request->query('persona'), Amanahku::personaIdsFor($role), true)) {
            session(['persona' => $request->query('persona')]);
        } elseif ($role === 'employee') {
            session(['persona' => 'employee']);
        }
        // A director's stored persona would be 'director'; collapse it so the persona label,
        // dash heading and preview switcher (which only know the four base roles) stay valid.
        $persona = Permissions::effectiveRole(session('persona', $role));
        // Clamp to the role's whitelist. Guards a session that stored a now-disallowed persona
        // before this gate existed (e.g. a manager who had switched to 'hr'): fall back to the
        // user's own role rather than keep rendering a dashboard they may no longer preview.
        if (! in_array($persona, Amanahku::personaIdsFor($role), true)) {
            $persona = $role;
            session(['persona' => $role]);
        }

        // Administration screens are restricted to privileged roles.
        if (in_array($screen, ['setup', 'settings', 'roles', 'cases', 'profile-test-admin', 'attendance-admin', 'position', 'timesheet-setup', 'leave-setup', 'staff-load', 'recurring', 'management-meeting'], true)) {
            $this->authorizeTenantRole($request, ['management', 'hr']);
        }
        // The all-staff timesheet view used to sit behind a tighter management/HR gate
        // because it carries RM cost. Money is gated separately (TimesheetController
        // MONEY_ROLES hides RM from managers) and DataScope narrows the roster to the
        // viewer's branch/department, so the canSeeAll block below is enough (CR-02):
        // a line manager sees their people's time, never their money.
        // Reports & Audit oversight surface + company-wide "see all" views (reachable
        // from the quick-action dock) open to management, HR, and immediate superiors —
        // anyone who oversees other staff. 'audit' moved here from admin-only so the
        // manager role can reach the Audit Logs alongside the two reports. 'reports' is
        // the company-wide analytics hub (headcount, department capacity, workload
        // split) — same oversight class as its siblings, just missing from this list.
        if (in_array($screen, ['oversight', 'attendance-report', 'timesheet-reports', 'leave-report', 'audit', 'team-board', 'profile-test-results', 'reports'], true)) {
            abort_unless(Permissions::canSeeAll($employee, $role), 403);
        }
        // Probation tracking also covers managers (their own new hires).
        if ($screen === 'probation') {
            $this->authorizeTenantRole($request, ['manager', 'management', 'hr']);
        }
        // Onboarding content library is authored by the same privileged roles that run onboarding.
        if ($screen === 'onboarding-content') {
            $this->authorizeTenantRole($request, ['manager', 'management', 'hr']);
        }
        // Feature gate: a screen whose gating module is disabled for this tenant reads
        // as absent (404), so a switched-off module looks like it was never installed.
        // Core screens have no gating module and always pass.
        if (! app(FeatureManager::class)->screenAllowed($tenant, $screen)) {
            abort(404);
        }

        $data = $this->screenData($request, $screen, $persona, $employee);

        $page = Amanahku::page($screen);
        if ($screen === 'dash') {
            $dashData = $this->dashboardData($request, $employee, $role);
            $data = array_merge($data, $dashData);
            // Legacy title/sub kept in sync from $head so anything still reading
            // pageTitle/pageSub (the shared layout's <title> tag, breadcrumb h1) shows the
            // real greeting rather than the static "Dashboard" placeholder.
            $page = array_merge($page, ['title' => $dashData['head']['h1'], 'title_ms' => $dashData['head']['h1_ms'] ?? $dashData['head']['h1'], 'sub' => $dashData['head']['sub'], 'sub_ms' => $dashData['head']['sub']]);
        }
        // Profile header reflects the actual employee being viewed.
        if ($screen === 'profile' && ! empty($data['profile'])) {
            $p = $data['profile'];
            $page = [
                'title' => $p->name,
                'sub' => trim("{$p->positionBand?->title} · {$p->department?->name} · {$p->branch?->name}", ' ·'),
                'crumb' => ['People', 'Employees', $p->name],
            ];
        }

        return $this->wrapScreen($request, $screen, $role, $persona, $employee, $tenant, $page, $data);
    }

    /**
     * The page chrome every screen shares: nav, persona strip, quick actions, the
     * Knowledge/Message header context, and the view resolution (a legacy slug that
     * was merged into another screen, a `$viewOverride` for a screen rendered from a
     * route that isn't the `/app/{screen}` catch-all, or `screens.$screen`/`screens.empty`).
     * Split out of screen() so a one-off page — e.g. the CR-11 event detail page, which
     * needs its own `/app/events/{event}` route rather than a screen slug — gets exactly
     * the same shell without duplicating this assembly.
     *
     * @param  array{title: string, sub: string, title_ms?: string, sub_ms?: string, crumb?: list<string>}  $page
     * @param  array<string, mixed>  $data
     */
    private function wrapScreen(Request $request, string $screen, string $role, string $persona, ?Employee $employee, Tenant $tenant, array $page, array $data, ?string $viewOverride = null): ViewContract
    {
        // claim-approvals was merged into the unified claims screen, and
        // project-quick-create into the Projects register; both slugs still resolve
        // (deep links, bookmarks) and land on the screen that replaced them.
        $viewScreen = $viewOverride ?? match ($screen) {
            'claim-approvals' => 'claims',
            'project-quick-create' => 'projects',
            default => $screen,
        };
        $view = View::exists($viewScreen) ? $viewScreen : (View::exists("screens.$viewScreen") ? "screens.$viewScreen" : 'screens.empty');

        return view($view, array_merge([
            'screen' => $screen,
            // Embed mode ( ?embed=1 ): renders the screen bare — no sidebar, header or
            // side panels — so the Setup wizard can inline it in a same-origin iframe.
            'embed' => $request->boolean('embed'),
            'persona' => $persona,
            'role' => $role,
            'roleLabel' => Amanahku::roleLabel($persona),
            'tenant' => ['name' => $tenant->name, 'initials' => $tenant->initials, 'color' => $tenant->color, 'plan' => $tenant->plan],
            'nav' => $this->navModel($screen, $role, $tenant),
            'aiEnabled' => app(FeatureManager::class)->enabled($tenant, 'ai.assistant'),
            // Gates KPI/performance widgets embedded on OTHER screens (e.g. the profile
            // KPI stat card + KPI History tab). Screen-scoped so it also tracks a future
            // KPI-only split, not just the whole Performance module being off.
            'perfEnabled' => app(FeatureManager::class)->screenAllowed($tenant, 'kpi'),
            // Only the personas this role may preview (manager sees Employee + Manager, not
            // Management/HR). Keyed off the real role — not $persona — so a user previewing
            // 'employee' still gets their full tab set and can switch back.
            'personas' => Amanahku::personasFor($role),
            // Strip appears only where there is more than one persona to switch between,
            // i.e. every privileged role; a plain employee (single tab) never sees it.
            'showPersona' => $screen === 'dash' && count(Amanahku::personasFor($role)) > 1,
            'pageTitle' => $page['title'],
            'pageSub' => $page['sub'],
            'pageTitleMs' => $page['title_ms'] ?? $page['title'],
            'pageSubMs' => $page['sub_ms'] ?? $page['sub'],
            'aiMessages' => Amanahku::aiMessages($persona, $screen),
            'aiPrompts' => Amanahku::aiPrompts(),
            'employee' => $employee,
            // Drives the persistent "finish your profile" nudge (banner + dash). Null when
            // the signed-in user has no employee record in this workspace.
            'profileCompletion' => $employee ? app(ProfileCompletion::class)->summary($employee) : null,
        ], $this->quickActions($employee, $role), app(KnowledgeController::class)->context($employee), app(MessageController::class)->context($employee), $data));
    }

    /**
     * CR-11: one event's detail page — attendee list, and (once
     * CompanyEvent::isOver()) the Photos/Comments/Lessons learnt sections. Same shell
     * as the `events` screen; nav highlighting and the module gate both key off
     * 'events' even though the URL carries an id, not a screen slug.
     */
    public function eventShow(Request $request, CompanyEvent $event): ViewContract
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($event->tenant_id === $tenant?->id, 404);
        abort_unless(app(FeatureManager::class)->screenAllowed($tenant, 'events'), 404);

        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        $employee = $request->attributes->get('employee');
        $persona = Permissions::effectiveRole(session('persona', $role));
        if (! in_array($persona, Amanahku::personaIdsFor($role), true)) {
            $persona = $role;
        }

        $data = app(EventController::class)->show($request, $event, $employee);

        $page = [
            'title' => $event->title,
            'sub' => 'Company event',
            'crumb' => ['Events', $event->title],
        ];

        return $this->wrapScreen($request, 'events', $role, $persona, $employee, $tenant, $page, $data, 'screens.event-show');
    }

    /**
     * CR-21: Office Requests Insights — requests per month, average days to close and the
     * top-voted items. PM and above only (`Permissions::effectiveRole` in manager/hr/
     * management, director collapses into management). JSON for an API/AJAX caller, the
     * same numbers rendered as HTML otherwise — same shell as the `office-requests` screen.
     */
    public function officeRequestInsights(Request $request): ViewContract|JsonResponse
    {
        $tenant = app(CurrentTenant::class)->get();
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        abort_unless(in_array($role, ['manager', 'hr', 'management'], true), 403);

        $data = app(OfficeRequestController::class)->insightsData($request);

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        $employee = $request->attributes->get('employee');
        $persona = Permissions::effectiveRole(session('persona', $role));
        if (! in_array($persona, Amanahku::personaIdsFor($role), true)) {
            $persona = $role;
        }

        $page = [
            'title' => 'Office Requests — Insights',
            'title_ms' => 'Permintaan Pejabat — Wawasan',
            'sub' => 'Requests per month, average time to close, and the top-voted items.',
            'sub_ms' => 'Permintaan setiap bulan, purata masa untuk selesai, dan item paling banyak undian.',
            'crumb' => ['Office Requests', 'Insights'],
        ];

        return $this->wrapScreen($request, 'office-requests', $role, $persona, $employee, $tenant, $page, $data, 'screens.office-requests-insights');
    }

    /**
     * CR-17: the dedicated page for a branch/company-scope manager (dashboard-slots.md
     * keeps the `management` band FINAL_APPROVAL_ROLES-only; CR32Test pins that a manager
     * never sees it). FINAL_APPROVAL_ROLES read the same page company-wide. Authorization
     * and scope resolution live in ManagementExceptionsController::pageData(), which
     * aborts 403 itself for anyone the screen is not for.
     */
    public function managementExceptions(Request $request): ViewContract
    {
        $tenant = app(CurrentTenant::class)->get();
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));

        $data = app(ManagementExceptionsController::class)->pageData($request);

        $employee = $request->attributes->get('employee');
        $persona = Permissions::effectiveRole(session('persona', $role));
        if (! in_array($persona, Amanahku::personaIdsFor($role), true)) {
            $persona = $role;
        }

        $page = [
            'title' => 'Management Exceptions',
            'title_ms' => 'Pengecualian Pengurusan',
            'sub' => 'Lateness today and overdue by Primary Owner.',
            'sub_ms' => 'Lewat hari ini dan tertunggak mengikut Pemilik Utama.',
            'crumb' => ['Management Exceptions'],
        ];

        return $this->wrapScreen($request, 'management-exceptions', $role, $persona, $employee, $tenant, $page, $data, 'screens.management-exceptions');
    }

    /**
     * One dashboard card, rebuilt for the period its arrows are pointing at.
     *
     * The arrows swap this markup into the card in place rather than reloading
     * the dashboard, so scroll position, open folds and the other cards' periods
     * all survive. Every gate the dashboard applies is applied again here: the
     * page not rendering a card is not a gate, and a hand-typed URL must not be
     * able to reach a widget the viewer's role or the tenant's modules keep off.
     */
    public function dashboardWidgetPartial(Request $request, string $widget): ViewContract
    {
        // Only the cards that actually carry arrows. Everything else has no period
        // to ask for, so the request is meaningless rather than merely empty.
        abort_unless(DashboardWidgets::periodUnit($widget) !== null, 404);

        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        abort_unless(in_array($widget, DashboardWidgets::forRole($role), true), 404);

        $screen = DashboardWidgets::gatingScreen($widget);
        abort_unless(
            $screen === null || app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), $screen),
            404,
        );

        $at = $request->query('at');

        return view('partials.dash.widget-inner', [
            'id' => $widget,
            'w' => $this->dashboardWidget($widget, $request, $request->attributes->get('employee'), is_string($at) ? $at : null),
        ]);
    }

    /**
     * Save the signed-in user's widget visibility and drag order.
     * `tasks` is pinned (DashboardPrefs::merge strips it from `hidden` no matter
     * what the client sends) — a user must never be able to bury their own
     * action list.
     */
    public function updateDashboardPrefs(UpdateDashboardPrefsRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->dashboard_prefs = DashboardPrefs::merge(
            $user->dashboard_prefs,
            $request->input('hidden', []),
            $request->input('order', []),
            $request->has('plain') ? $request->boolean('plain') : null,
        );
        $user->save();

        return response()->json(['ok' => true]);
    }

    /**
     * CSV export of the tenant's audit log, newest first. Management-tier and HR only —
     * the same tier that gets final approval on requests (Permissions::FINAL_APPROVAL_ROLES
     * minus the distinction between them here: both may see the whole company's ledger).
     */
    public function auditExport(Request $request): StreamedResponse
    {
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        abort_unless(in_array($role, ['management', 'hr'], true), 403);

        $columns = ['id', 'created_at', 'actor_name', 'action', 'target', 'subject_type', 'subject_id', 'field', 'old_value', 'new_value', 'reason', 'source'];

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            AuditLog::query()->latest('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        $row->created_at?->timezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i:s'),
                        $row->actor_name,
                        $row->action,
                        $row->target,
                        $row->subject_type,
                        $row->subject_id,
                        $row->field,
                        $row->old_value,
                        $row->new_value,
                        $row->reason,
                        $row->source,
                    ]);
                }
            });

            fclose($out);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Always-on data for the persistent sidebar quick-action dock (clock · task · timesheet)
     * that sits above the nav on every screen. Returns qaShow=false when the signed-in user
     * has no employee record in this workspace — nothing to clock or log against.
     */
    private function quickActions(?Employee $employee, string $role = 'employee'): array
    {
        if (! $employee) {
            return ['qaShow' => false, 'qaCanSeeAll' => false];
        }

        $tenant = app(CurrentTenant::class)->get();
        // Prefer the still-open punch over today's record: an overnight shift's open
        // record is dated yesterday, so an onDate() lookup showed "not clocked in" and
        // offered "Clock in" to someone mid-shift. Falls back to today's row so a
        // normally closed day still reports its in/out times.
        $today = $employee->attendanceRecords()->openPunch(now())->first()
            ?? $employee->attendanceRecords()->onDate(now())->first();

        // CR-03: approved days divided by working days from Monday to today (this
        // week), excluding days fully locked by leave/holiday — the sidebar's only
        // "Timesheet %" figure.
        $tsEnabled = app(FeatureManager::class)->screenAllowed($tenant, 'timesheets');
        $tsPct = 0.0;
        $ts = null;
        if ($tsEnabled) {
            $ts = Timesheet::with(['entries', 'days'])
                ->where('employee_id', $employee->id)
                ->forWeek(now()->startOfWeek())
                ->first();

            $weekStart = now()->startOfWeek();
            $locked = app(LockedDays::class)->forWeek($employee, $weekStart);
            $rules = app(DayRules::class);
            $todayDate = now()->startOfDay();

            $workingDays = array_filter(
                $rules->weekWorkingDays($weekStart),
                fn (string $iso) => Carbon::parse($iso)->lte($todayDate) && ($locked[$iso]['percentage'] ?? 0) < DayCapacity::for($iso),
            );

            if ($workingDays !== []) {
                $approved = $ts
                    ? $ts->days->filter(fn ($d) => $d->status === 'approved' && in_array($d->entry_date->toDateString(), $workingDays, true))->count()
                    : 0;
                $tsPct = round($approved / count($workingDays) * 100, 1);
            }
        }

        // Overdue = past Friday 5pm AND this week isn't fully filled. Drives the
        // app-wide red banner. One light indexed lookup per page load.
        $tsOverdue = false;
        if ($tsEnabled) {
            // Reuse the sheet fetched above instead of letting isLate re-query it.
            $tsOverdue = app(TimesheetCompliance::class)
                ->isLate($employee, now()->startOfWeek(), $ts, true);
        }

        return [
            'qaShow' => true,
            'qaCi' => $today?->clock_in,
            'qaCo' => $today?->clock_out,
            'qaTsEnabled' => $tsEnabled,
            'qaTsPct' => $tsPct,
            'qaTsOverdue' => $tsOverdue,
            // Unlocks the "See all" company-wide links under each dock row.
            'qaCanSeeAll' => Permissions::canSeeAll($employee, $role),
        ];
    }

    /** Build only the data the requested screen needs. */
    private function screenData(Request $request, string $screen, string $persona, ?Employee $employee): array
    {
        return match ($screen) {
            // Dashboard scope data (head/chips/queue/railCards/…) is built and merged in
            // by screen() itself, once $scope is known — nothing to add here.
            'dash' => [],
            'directory' => $this->directoryData($request),
            'staff-load' => $this->staffLoadData($request),
            'profile' => $this->profileData($request),
            'profile-test' => app(ProfileTestController::class)->screenData($request, $employee),
            'profile-test-admin' => app(ProfileTestController::class)->adminData($request),
            'profile-test-results' => app(ProfileTestController::class)->resultsData($request, $employee),
            'board' => $this->boardScreenData($request, $employee),
            'team-board' => $this->teamBoardData($request),
            'workload' => $this->workloadData(),
            'attendance' => $this->attendanceData($employee),
            'leave' => $this->leaveData($request, $employee),
            'payroll' => $this->payrollData($request, $employee),
            'kpi' => ['items' => $employee?->kpiItems()->get() ?? collect()],
            'achievements' => $this->achievementsData($request->attributes->get('tenantRole', 'employee')),
            'reviews' => $this->reviewsData($employee, $request->attributes->get('tenantRole', 'employee')),
            'onboarding' => app(OnboardingController::class)->screenData($request, $employee),
            'onboarding-content' => app(OnboardingContentController::class)->screenData($request),
            // Both slugs render the unified claims screen (claim-approvals just defaults to
            // the Approvals tab — see screens/claims.blade.php and the view resolve below).
            'claims', 'claim-approvals' => $this->claimsData($request, $employee),
            'assets' => $this->assetsData($request),
            'training' => $this->trainingData($request),
            'orgchart' => app(OrgController::class)->screenData($request, $employee),
            'reports' => $this->reportsData(),
            'handbook' => $this->handbookData($employee),
            'settings' => $this->settingsData($request),
            'attendance-admin' => app(AttendanceAdminController::class)->screenData($request),
            'leave-setup' => app(LeaveSetupController::class)->screenData($request),
            'recurring' => app(RecurringTaskController::class)->screenData($request),
            'management-meeting' => app(ManagementMeetingController::class)->screenData($request),
            'attendance-report' => app(AttendanceReportController::class)->screenData($request),
            'leave-report' => app(LeaveReportController::class)->screenData($request),
            'position' => app(PositionController::class)->screenData($request),
            'roles' => $this->rolesData(),
            'setup' => app(SetupController::class)->screenData($request),
            'audit' => ['logs' => $this->auditLogsData()],
            'changelog' => ['releases' => Changelog::releases()],
            'roster' => app(RosterController::class)->screenData($request, $employee),
            'documents' => app(DocumentController::class)->screenData($request, $employee),
            'surveys' => app(SurveyController::class)->screenData($request, $employee),
            'helpdesk' => app(HelpdeskController::class)->screenData($request, $employee),
            'events' => app(EventController::class)->screenData($request, $employee),
            'office-requests' => app(OfficeRequestController::class)->screenData($request, $employee),
            'shared-resources' => app(SharedResourceController::class)->screenData($request),
            'offboarding' => app(OffboardingController::class)->screenData($request, $employee),
            'goals' => app(GoalController::class)->screenData($request, $employee),
            'recruitment' => app(RecruitmentController::class)->screenData($request, $employee),
            'loans' => app(LoanController::class)->screenData($request, $employee),
            'travel' => app(TravelController::class)->screenData($request, $employee),
            'rooms' => app(RoomController::class)->screenData($request, $employee),
            'cases' => app(CaseController::class)->screenData($request, $employee),
            'ideas' => app(IdeaController::class)->screenData($request, $employee),
            'knowledge-bank' => app(KnowledgeController::class)->screenData($request, $employee),
            'tot' => app(TotController::class)->screenData($request, $employee),
            'tot-roster' => app(TotController::class)->rosterData($request, $employee),
            'messages' => app(MessageController::class)->screenData($request, $employee),
            'benefits' => app(BenefitController::class)->screenData($request, $employee),
            'expenses' => app(ExpenseController::class)->screenData($request, $employee),
            'probation' => app(ProbationController::class)->screenData($request, $employee),
            'overtime' => app(OvertimeController::class)->screenData($request, $employee),
            'calendar' => app(CalendarController::class)->screenData($request, $employee),
            'resignation' => app(ResignationController::class)->screenData($request, $employee),
            'compliance' => app(ComplianceController::class)->screenData($request, $employee),
            'timesheets' => app(TimesheetController::class)->screenData($request, $employee),
            'timesheet-setup' => app(TimesheetAdminController::class)->screenData($request),
            'projects', 'project-quick-create' => app(ProjectController::class)->screenData($request),
            'timesheet-reports' => app(TimesheetController::class)->reportData($request, $employee),
            'learning' => app(LearningController::class)->screenData($request, $employee),
            'skills' => app(SkillController::class)->screenData($request, $employee),
            'referrals' => app(ReferralController::class)->screenData($request, $employee),
            'shiftswap' => app(ShiftSwapController::class)->screenData($request, $employee),
            'pettycash' => app(PettyCashController::class)->screenData($request, $employee),
            'vehicles' => app(VehicleController::class)->screenData($request, $employee),
            'wellness' => app(WellnessController::class)->screenData($request, $employee),
            'security' => ['passkeyEnabled' => app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'security.passkey') !== 'off'],
            default => [],
        };
    }

    private function auditLogsData(): Collection
    {
        $logs = AuditLog::latest()->take(50)->get();

        $displayNames = Employee::withoutGlobalScope('tenant')
            ->whereIn('user_id', $logs->pluck('user_id')->filter())
            ->get()
            ->keyBy('user_id');

        return $logs->map(function (AuditLog $log) use ($displayNames): object {
            $emp = $displayNames->get($log->user_id);

            return (object) [
                'action' => $log->action,
                'target' => $log->target,
                'actor_name' => $emp?->display_name ?? $log->actor_name,
                'created_at' => $log->created_at,
                'field' => $log->field,
                'old' => $log->displayValue('old_value'),
                'new' => $log->displayValue('new_value'),
                'reason' => $log->reason,
            ];
        });
    }
}
