<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Asset;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EmploymentType;
use App\Models\Goal;
use App\Models\HandbookSection;
use App\Models\LeaveRequest;
use App\Models\LoanRequest;
use App\Models\OvertimeRequest;
use App\Models\PolicyAcknowledgement;
use App\Models\Position;
use App\Models\ProbationReview;
use App\Models\StaffLevel;
use App\Models\TrainingRecord;
use App\Models\UserPermission;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Services\FeatureManager;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Directory, profile, assets, training, handbook and reports screen data for
 * AppController::screen(). Split out of AppController purely for file size —
 * every method still runs on the controller instance ($this).
 */
trait BuildsPeopleData
{
    private function directoryData(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $dept = $request->query('dept');
        $status = $request->query('status');

        // Archived view is an HR/management recovery tool: it lists staff who have been
        // archived out of the directory so they can be restored. Non-privileged roles
        // never get it, so a crafted ?view=archived is silently ignored for them.
        $canArchive = $this->hasTenantRole($request, ['management', 'hr']);
        $archived = $canArchive && $request->query('view') === 'archived';
        $listScope = fn () => $archived ? Employee::archived() : Employee::active();

        // Apply the member's data scope: a manager scoped to their branch/department
        // only sees those staff. Default 'company' is a no-op (full visibility).
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $dataScope = app(DataScope::class);

        $query = $dataScope->applyToEmployees($listScope()->with(['department', 'branch', 'positionBand']), $scope, $self)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('name', 'like', "%$q%")
                ->orWhere('email', 'like', "%$q%")
                // Match the band title, department and branch the way they're shown.
                ->orWhereHas('positionBand', fn ($p) => $p->where('title', 'like', "%$q%"))
                ->orWhereHas('department', fn ($d) => $d->where('name', 'like', "%$q%"))
                ->orWhereHas('branch', fn ($r) => $r->where('name', 'like', "%$q%"))))
            ->when($dept, fn ($b) => $b->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->when($status, fn ($b) => $b->where('status', $status))
            ->orderBy('name');

        // Salary is money-sensitive: only directors and HR see the raw salary column.
        // Plain managers and the management role do NOT — salary is board + HR only.
        $canSeeSalary = $this->hasTenantRole($request, ['director', 'hr']);

        return array_merge([
            'employees' => $query->paginate(10)->withQueryString(),
            'total' => $dataScope->applyToEmployees($listScope(), $scope, $self)->count(),
            // Badge on the Archived toggle so HR sees there are people to restore.
            'archivedCount' => $canArchive ? $dataScope->applyToEmployees(Employee::archived(), $scope, $self)->count() : 0,
            'departments' => Department::orderBy('name')->pluck('name'),
            'filters' => ['q' => $q, 'dept' => $dept, 'status' => $status],
            'canSeeSalary' => $canSeeSalary,
            'archived' => $archived,
            'canArchive' => $canArchive,
        ], $this->orgOptions());
    }

    /**
     * Data for the Administration → Add & Import Staff screen: the add-employee form's
     * option lists plus the salary-field gate. The directory screen is view-only; all
     * staff data-loading (add / CSV import / provision logins) lives here.
     */
    private function staffLoadData(Request $request): array
    {
        return array_merge($this->orgOptions(), [
            'canSeeSalary' => $this->hasTenantRole($request, ['director', 'hr']),
        ]);
    }

    /** Department + branch + staff-level + employment-type option lists for the add/edit employee forms. */
    private function orgOptions(): array
    {
        return [
            'allDepartments' => Department::orderBy('name')->get(['id', 'name']),
            'allBranches' => Branch::orderBy('name')->get(['id', 'name']),
            'allStaffLevels' => StaffLevel::orderByRaw('`rank` IS NULL, `rank`')->orderBy('name')->get(['id', 'name']),
            'allEmploymentTypes' => EmploymentType::orderBy('name')->get(['id', 'name']),
            // Rate-card bands for the staff form's position picker (carries dept, title, max salary).
            'allPositions' => Position::with(['department', 'staffLevel'])->orderBy('sort')->orderBy('title')->get(),
            // Current staff as candidate managers for the profile "Reports to" picker —
            // the link that builds the org chart. The form excludes the person themselves.
            'allManagers' => Employee::active()->orderBy('name')->get(['id', 'name', 'nickname']),
        ];
    }

    private function profileData(Request $request): array
    {
        $with = ['positionBand', 'department', 'branch', 'reportsTo', 'careerTimeline', 'kpiItems', 'leaveBalances.leaveType', 'workItems', 'assets', 'trainingRecords'];

        // A specific employee (from a directory row), else the signed-in user's own record.
        // No arbitrary showcase fallback: an unresolved employee renders the empty state, not
        // a stranger's full record. Employee queries are tenant-scoped by the global scope,
        // so emp/own can never cross tenants.
        $e = $request->filled('emp')
            ? Employee::with($with)->find($request->query('emp'))
            : null;
        $own = $request->attributes->get('employee');
        $e ??= $own ? Employee::with($with)->find($own->id) : null;

        $tenant = app(CurrentTenant::class)->get();
        $features = app(FeatureManager::class);

        // The security fix: directory rows and the header people-search both deep-link
        // ?emp=, so without this gate any signed-in employee could read any colleague's
        // full record. Full profile is one of four things (see profileViewerOutranksOrLeads
        // below for the seniority/reporting-line half): the person themselves,
        // management/HR/director, the viewer's staff level strictly outranking the
        // subject's, or the viewer sitting anywhere above the subject in the reporting
        // line. This also folds in what canSeeAttendance used to compute on its own — same
        // formula, one variable. Anyone else gets a slim public card, never a 403 — header
        // search and directory clicks must not dead-end.
        $canViewFull = $e && (
            ($own && $own->id === $e->id)
            || $this->hasTenantRole($request, ['management', 'hr', 'director'])
            || ($own && $this->profileViewerOutranksOrLeads($own, $e))
        );

        // Money is board + HR only, same rule as the directory salary column and the
        // server-side write guard in EmployeeController. Managers still verify claims/OT on
        // the approvals screens; they just don't get a pay dossier on the profile. Unlike
        // canViewFull, a manager verifying their own report does NOT gain this.
        $canSeeMoney = $e && (
            ($own && $own->id === $e->id)
            || $this->hasTenantRole($request, ['director', 'hr'])
        );

        // Director keeps edit rights: hasTenantRole() collapses director into the management
        // super-set (Permissions::effectiveRole), unlike a raw in_array($role, ...) check.
        $canEdit = $this->hasTenantRole($request, ['management', 'hr']);

        // Every tab/section gate is canViewFull (or canSeeMoney for the pay dossier) ANDed
        // with the tenant's module flag — a tab must never render for a module the tenant
        // switched off, even for a viewer who'd otherwise be allowed to see it.
        $leaveGate = $canViewFull && $features->screenAllowed($tenant, 'leave');
        $kpiGate = $canViewFull && $features->screenAllowed($tenant, 'kpi');
        $goalsGate = $canViewFull && $features->screenAllowed($tenant, 'goals');
        $reviewsGate = $canViewFull && $features->screenAllowed($tenant, 'reviews');
        $probationGate = $canViewFull && $features->screenAllowed($tenant, 'probation');
        $skillsGate = $canViewFull && $features->screenAllowed($tenant, 'skills');
        $payrollGate = $canSeeMoney && $features->screenAllowed($tenant, 'payroll');
        $claimsGate = $canSeeMoney && $features->screenAllowed($tenant, 'claims');
        $loansGate = $canSeeMoney && $features->screenAllowed($tenant, 'loans');
        $overtimeGate = $canSeeMoney && $features->screenAllowed($tenant, 'overtime');

        $assignedTasks = ($e && $canViewFull)
            ? WorkItem::where('employee_id', $e->id)
                ->whereNotNull('assigned_by_id')
                ->with('assignedBy')
                ->orderByRaw('due_at IS NULL, due_at ASC')
                ->get()
            : collect();

        // Attendance rides the Leave & Attendance tab's gate — canViewFull plus the `leave`
        // module flag — rather than canViewFull alone, so a tenant with Leave off doesn't
        // leak attendance through the same tab.
        $attendance = ($e && $leaveGate)
            ? $e->attendanceRecords()
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->orderByDesc('date')
                ->get()
            : collect();

        $leaveHistory = ($e && $leaveGate)
            ? $e->leaveRequests()->with('leaveType')->orderByDesc('date_from')->limit(12)->get()
            : collect();

        $payslips = ($e && $payrollGate)
            ? $e->payslips()->orderByDesc('id')->limit(12)->get()
            : collect();

        $claims = ($e && $claimsGate)
            ? $e->claims()->orderByDesc('date')->limit(12)->get()
            : collect();

        $loans = ($e && $loansGate)
            ? LoanRequest::where('employee_id', $e->id)->orderByDesc('created_at')->limit(12)->get()
            : collect();

        $overtime = ($e && $overtimeGate)
            ? OvertimeRequest::where('employee_id', $e->id)->orderByDesc('ot_date')->limit(12)->get()
            : collect();

        $goals = ($e && $goalsGate)
            ? Goal::where('employee_id', $e->id)->with('keyResults')->orderByDesc('created_at')->limit(12)->get()
            : collect();

        $reviews = ($e && $reviewsGate)
            ? $e->performanceReviews()->orderByDesc('review_date')->limit(12)->get()
            : collect();

        $probation = ($e && $probationGate)
            ? ProbationReview::where('employee_id', $e->id)->orderByDesc('created_at')->limit(12)->get()
            : collect();

        // Skill matrix is a current snapshot, not a history list — no 12-row cap.
        $skills = ($e && $skillsGate)
            ? EmployeeSkill::where('employee_id', $e->id)->with('skill')->get()
            : collect();

        return array_merge([
            'profile' => $e,
            'canViewFull' => $canViewFull,
            'canEdit' => $canEdit,
            'canAssign' => $this->hasTenantRole($request, ['manager', 'management', 'hr']),
            // Salary is board + HR only — gates the salary field inside the edit form so the
            // management role can edit everyone without seeing or changing pay (same rule as
            // the directory column and the server-side write guard in EmployeeController).
            'canSeeSalary' => $this->hasTenantRole($request, ['director', 'hr']),
            'canSeeMoney' => $canSeeMoney,
            'assignedTasks' => $assignedTasks,
            'canSeeAttendance' => $leaveGate,
            'attendance' => $attendance,
            'leaveGate' => $leaveGate,
            'leaveHistory' => $leaveHistory,
            'kpiGate' => $kpiGate,
            'goalsGate' => $goalsGate,
            'goals' => $goals,
            'reviewsGate' => $reviewsGate,
            'reviews' => $reviews,
            'probationGate' => $probationGate,
            'probation' => $probation,
            'skillsGate' => $skillsGate,
            'skills' => $skills,
            'payrollGate' => $payrollGate,
            'payslips' => $payslips,
            'claimsGate' => $claimsGate,
            'claims' => $claims,
            'loansGate' => $loansGate,
            'loans' => $loans,
            'overtimeGate' => $overtimeGate,
            'overtime' => $overtime,
        ], $canEdit ? $this->orgOptions() : []);
    }

    /**
     * Rules 3 and 4 of the profile visibility gate (rules 1 and 2 — self, and
     * management/hr/director — live inline in profileData()). Either path alone misses a
     * real case the other covers, so both stay:
     *
     * - Rank (rule 3): the viewer's staff level strictly outranks the subject's, company
     *   wide. Gives a Director-level viewer with zero reports (e.g. Suandy) the same
     *   visibility as one who has reports, and lets a senior level see junior staff
     *   outside their own branch/department without depending on the org chart being
     *   correct. Requires both ranks non-null — a null rank on either side means this
     *   rule cannot apply (fail closed, see class docblock in the migration).
     * - Ancestor (rule 4): the viewer sits anywhere above the subject in the reporting
     *   line, direct or transitive. Rank alone regresses managers whose own staff level
     *   happens to be junior-tagged relative to people they manage (e.g. an Exec-level
     *   lead with Manager-level reports) — without this they'd lose sight of their own
     *   team. verifierIds() covers the immediate primary + dotted-line managers; the
     *   reports_to_id walk on top covers everyone further up that chain (a skip-level
     *   grandmanager, etc). Archived intermediates are walked through regardless: the org
     *   position held even if the person filling it has since left.
     */
    private function profileViewerOutranksOrLeads(Employee $viewer, Employee $subject): bool
    {
        if ($this->staffLevelRankOutranks($viewer, $subject)) {
            return true;
        }

        return $this->isReportingLineAncestor($viewer, $subject);
    }

    private function staffLevelRankOutranks(Employee $viewer, Employee $subject): bool
    {
        $viewerRank = $viewer->staffLevel?->rank;
        $subjectRank = $subject->staffLevel?->rank;

        return $viewerRank !== null && $subjectRank !== null && $viewerRank < $subjectRank;
    }

    /**
     * True when $viewer is a direct or transitive superior of $subject: either a primary
     * or dotted-line manager (verifierIds()), or anywhere further up the raw reports_to_id
     * chain. The chain is walked without regard to whether an intermediate manager is
     * archived — the reporting relationship held historically even if that seat is now
     * vacant — so a visited-id set plus a depth cap is the only guard against a corrupt
     * cyclic chain hanging the request.
     */
    private function isReportingLineAncestor(Employee $viewer, Employee $subject): bool
    {
        if (in_array($viewer->id, $subject->verifierIds(), true)) {
            return true;
        }

        $visited = [];
        $currentId = $subject->reports_to_id;
        $depth = 0;

        while ($currentId !== null && ! in_array($currentId, $visited, true) && $depth < 50) {
            if ($currentId === $viewer->id) {
                return true;
            }

            $visited[] = $currentId;
            $depth++;

            // Raw query builder, not the Employee model: archived staff carry no soft-
            // delete/global scope of their own (scopeActive()/scopeArchived() are opt-in
            // local scopes), but going straight to the table sidesteps ever having to
            // reason about that as scopes evolve — an archived intermediate must still be
            // walked through. Cast to int: the raw query builder doesn't apply Eloquent's
            // key casting, and the strict comparisons above need matching types.
            $next = DB::table('employees')->where('id', $currentId)->value('reports_to_id');
            $currentId = $next === null ? null : (int) $next;
        }

        return false;
    }

    private function assetsData(Request $request): array
    {
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);

        return [
            'assets' => Asset::with('employee')->orderByDesc('status')->orderBy('name')->get(),
            'recipients' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name', 'nickname']) : collect(),
        ];
    }

    private function trainingData(Request $request): array
    {
        $privileged = $this->hasTenantRole($request, ['manager', 'management', 'hr']);

        return [
            // Archived (departed) staff's training is no longer actionable — drop it from the
            // list AND the Courses/Completed/Mandatory/Overdue cards (all derive from $records).
            'records' => TrainingRecord::with('employee')->whereHas('employee', fn ($q) => $q->active())->orderBy('due_at')->get(),
            // user_id + department + position loaded so the assign picker can group staff by
            // role tier and show each person's job title.
            'recipients' => $privileged
                ? Employee::active()->with(['department:id,name', 'positionBand:id,title'])->orderBy('name')->get(['id', 'name', 'nickname', 'initials', 'avatar_color', 'department_id', 'user_id', 'position_id'])
                : collect(),
            // Tenant role per login (user_id → role) so the picker can rank staff
            // Director → Management → HR → Manager → Employee. No login = plain employee.
            'recipientRoles' => $privileged
                ? DB::table('tenant_user')
                    ->where('tenant_id', app(CurrentTenant::class)->id())
                    ->pluck('role', 'user_id')
                : collect(),
        ];
    }

    private function handbookData(?Employee $employee): array
    {
        $sections = HandbookSection::orderBy('sort')->get();
        $ackedIds = $employee
            ? PolicyAcknowledgement::where('employee_id', $employee->id)->pluck('handbook_section_id')->all()
            : [];

        $requiresAck = $sections->where('requires_ack', true);
        $headcount = max(Employee::active()->count(), 1);

        // Total acknowledgements across the required sections in ONE query (AK-PERF-02).
        // The old per-section ->count() closure was an N+1 on every /handbook load; the sum
        // of per-section counts equals a single count of ack rows on those sections.
        // Numerator constrained to active employees to match the active-headcount denominator
        // (:153) — an archived person's ack would otherwise push ackRate over 100%.
        $totalAcks = $requiresAck->isEmpty()
            ? 0
            : PolicyAcknowledgement::whereIn('handbook_section_id', $requiresAck->pluck('id'))
                ->whereHas('employee', fn ($q) => $q->active())
                ->count();

        return [
            'sections' => $sections->groupBy('category'),
            'ackedIds' => $ackedIds,
            'ackRate' => $requiresAck->isEmpty() ? 100 : (int) round(
                $totalAcks / ($requiresAck->count() * $headcount) * 100
            ),
        ];
    }

    private function reportsData(): array
    {
        $total = max(Employee::active()->count(), 1);

        return [
            'headcount' => Employee::active()->count(),
            'byDept' => $this->departmentCapacity(),
            'byStatus' => [
                ['k' => 'Active', 'v' => Employee::active()->where('status', 'active')->count(), 'c' => 'green'],
                ['k' => 'Probation', 'v' => Employee::active()->where('status', 'probation')->count(), 'c' => 'amber'],
                ['k' => 'On leave', 'v' => Employee::active()->where('status', 'on_leave')->count(), 'c' => 'grey'],
            ],
            // Live workload split — grouped by the Employee accessor (open work-item count),
            // not the frozen column, so these tiles agree with the workload screen and recs.
            'workload' => (function () {
                $byLoad = Employee::active()
                    ->withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=', 'done')])
                    ->get()->groupBy(fn ($e) => $e->workload);

                return [
                    ['k' => 'Healthy', 'v' => $byLoad->get('green')?->count() ?? 0, 'c' => 'green'],
                    ['k' => 'Near capacity', 'v' => $byLoad->get('amber')?->count() ?? 0, 'c' => 'amber'],
                    ['k' => 'Overloaded', 'v' => $byLoad->get('red')?->count() ?? 0, 'c' => 'red'],
                ];
            })(),
            'leaveApproved' => LeaveRequest::where('status', 'approved')->whereHas('employee', fn ($q) => $q->active())->count(),
            'leavePending' => LeaveRequest::where('status', 'submitted')->whereHas('employee', fn ($q) => $q->active())->count(),
            'pct' => fn ($n) => round($n / $total * 100),
        ];
    }

    /** Roles screen: workspace members shown by nickname, full legal name on hover. */
    private function rolesData(): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $members = $tenant->users()->orderBy('name')->get();

        $displayNames = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $members->each(function ($u) use ($displayNames): void {
            $emp = $displayNames->get($u->id);
            $u->displayName = $emp?->display_name ?? $u->name;
        });

        return [
            'members' => $members,
            'permissionGroups' => Permissions::overridableGrouped(),
            'permOverrides' => UserPermission::all()
                ->groupBy('user_id')
                ->map(fn ($g) => $g->pluck('granted', 'permission')),
        ];
    }
}
