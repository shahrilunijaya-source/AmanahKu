<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsDashboardData;
use App\Http\Controllers\Concerns\BuildsNav;
use App\Http\Controllers\Concerns\BuildsPeopleData;
use App\Http\Controllers\Concerns\BuildsSettingsData;
use App\Http\Controllers\Concerns\BuildsWorkData;
use App\Http\Controllers\Concerns\RoutesApprovalsByReportingLine;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\UserPermission;
use App\Services\FeatureManager;
use App\Support\Amanahku;
use App\Support\Permissions;
use App\Support\ProfileCompletion;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;

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
            if ($tenant && $request->user()->tenants->contains('id', $tenant->id)) {
                return redirect()->route('tenant.enter', $tenant);
            }
        }

        return view('tenant.select', [
            'tenants' => $request->user()->tenants()
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
        abort_unless(request()->user()->tenants->contains('id', $tenant->id), 403);

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
        if (in_array($screen, ['setup', 'settings', 'roles', 'cases', 'profile-test-admin', 'attendance-admin', 'position', 'timesheet-setup', 'leave-setup', 'staff-load'], true)) {
            $this->authorizeTenantRole($request, ['management', 'hr']);
        }
        // Reports & Audit oversight surface + company-wide "see all" views (reachable
        // from the quick-action dock) open to management, HR, and immediate superiors —
        // anyone who oversees other staff. 'audit' moved here from admin-only so the
        // manager role can reach the Audit Logs alongside the two reports.
        if (in_array($screen, ['attendance-report', 'timesheet-reports', 'leave-report', 'audit', 'team-board', 'feedback'], true)) {
            abort_unless($this->canSeeAll($employee, $role), 403);
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
            $page = array_merge($page, Amanahku::dashHeading($persona, $this->dashStats($tenant, $persona, $employee), $employee));
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

        $view = View::exists("screens.$screen") ? "screens.$screen" : 'screens.empty';

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
            'crumbs' => array_merge([$tenant->name], $page['crumb']),
            // Parallel BM breadcrumb trail. Tenant name (proper noun) is not translated;
            // each English segment maps through Amanahku::crumbMs(), falling back to itself.
            'crumbsMs' => array_merge([$tenant->name], array_map([Amanahku::class, 'crumbMs'], $page['crumb'])),
            'aiMessages' => Amanahku::aiMessages($persona, $screen),
            'aiPrompts' => Amanahku::aiPrompts(),
            'employee' => $employee,
            // Drives the persistent "finish your profile" nudge (banner + dash). Null when
            // the signed-in user has no employee record in this workspace.
            'profileCompletion' => $employee ? app(ProfileCompletion::class)->summary($employee) : null,
        ], $this->quickActions($employee, $role), app(KnowledgeController::class)->context($employee), app(MessageController::class)->context($employee), $data));
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
        $today = $employee->attendanceRecords()->onDate(now())->first();

        // This week's allocated % for today, surfaced as the timesheet tile's progress.
        $tsEnabled = app(FeatureManager::class)->screenAllowed($tenant, 'timesheets');
        $tsPct = 0.0;
        $ts = null;
        if ($tsEnabled) {
            $ts = Timesheet::with('entries')
                ->where('employee_id', $employee->id)
                ->forWeek(now()->startOfWeek())
                ->first();
            if ($ts) {
                $todayStr = now()->toDateString();
                $tsPct = (float) $ts->entries
                    ->filter(fn ($e) => $e->entry_date->toDateString() === $todayStr)
                    ->sum(fn ($e) => (float) $e->percentage);
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
            'qaCanSeeAll' => $this->canSeeAll($employee, $role),
        ];
    }

    /**
     * True when the signed-in user may see company-wide attendance / tasks / timesheets:
     * management, HR, or an immediate superior (has at least one direct report). Used to
     * gate both the dock "See all" links and the screens they open.
     */
    private function canSeeAll(?Employee $employee, string $role): bool
    {
        // The 'manager' role is an immediate superior by definition; management and HR
        // oversee everyone. An 'employee'-role user still qualifies if the org chart
        // gives them at least one direct report (reports_to_id points at them).
        // effectiveRole() collapses 'director' → 'management' so a board-tier director
        // (a strict management super-set) reaches every oversight screen without a
        // direct report of their own — same single hinge hasTenantRole() relies on.
        if (in_array(Permissions::effectiveRole($role), ['manager', 'management', 'hr'], true)) {
            return true;
        }

        return $employee !== null
            && Employee::active()->where('reports_to_id', $employee->id)->exists();
    }

    /** Build only the data the requested screen needs. */
    private function screenData(Request $request, string $screen, string $persona, ?Employee $employee): array
    {
        return match ($screen) {
            // Persona-shaped dashboard data, plus the real user's action queue (verify /
            // approve) merged in — the latter is keyed off the request, not $persona, so it
            // reflects genuine obligations even while previewing another persona.
            'dash' => array_merge($this->dashboardData($persona, $employee), $this->pendingActions($request)),
            'directory' => $this->directoryData($request),
            'staff-load' => $this->staffLoadData($request),
            'profile' => $this->profileData($request),
            'profile-test' => app(ProfileTestController::class)->screenData($request, $employee),
            'profile-test-admin' => app(ProfileTestController::class)->adminData($request),
            'board' => ['columns' => $this->boardColumns($employee, request('type', 'core')), 'boardType' => request('type', 'core')],
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
            'claims' => $this->claimsData($request, $employee),
            'assets' => $this->assetsData($request),
            'training' => $this->trainingData($request),
            'orgchart' => app(OrgController::class)->screenData($request, $employee),
            'reports' => $this->reportsData(),
            'handbook' => $this->handbookData($employee),
            'settings' => $this->settingsData($request),
            'attendance-admin' => app(AttendanceAdminController::class)->screenData($request),
            'leave-setup' => app(LeaveSetupController::class)->screenData($request),
            'attendance-report' => app(AttendanceReportController::class)->screenData($request),
            'leave-report' => app(LeaveReportController::class)->screenData($request),
            'position' => app(PositionController::class)->screenData($request),
            'roles' => [
                'members' => app(CurrentTenant::class)->get()->users()->orderBy('name')->get(),
                'permissionGroups' => Permissions::overridableGrouped(),
                'permOverrides' => UserPermission::all()
                    ->groupBy('user_id')
                    ->map(fn ($g) => $g->pluck('granted', 'permission')),
            ],
            'setup' => app(SetupController::class)->screenData($request),
            'feedback' => app(FeedbackController::class)->screenData($request),
            'audit' => ['logs' => AuditLog::latest()->take(50)->get()],
            'roster' => app(RosterController::class)->screenData($request, $employee),
            'documents' => app(DocumentController::class)->screenData($request, $employee),
            'surveys' => app(SurveyController::class)->screenData($request, $employee),
            'helpdesk' => app(HelpdeskController::class)->screenData($request, $employee),
            'events' => app(EventController::class)->screenData($request, $employee),
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
}
