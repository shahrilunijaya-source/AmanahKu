<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Asset;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\HandbookSection;
use App\Models\LeaveRequest;
use App\Models\PolicyAcknowledgement;
use App\Models\Position;
use App\Models\StaffLevel;
use App\Models\TrainingRecord;
use App\Models\WorkItem;
use App\Services\DataScope;
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
            'allManagers' => Employee::active()->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function profileData(Request $request): array
    {
        $with = ['positionBand', 'department', 'branch', 'reportsTo', 'careerTimeline', 'kpiItems', 'leaveBalances.leaveType', 'workItems', 'assets', 'trainingRecords'];

        // A specific employee (from a directory row), else the signed-in user's own
        // record, else the showcase profile as a last resort. Employee queries are
        // tenant-scoped by the global scope, so emp/own can never cross tenants.
        $e = $request->filled('emp')
            ? Employee::with($with)->find($request->query('emp'))
            : null;
        $own = $request->attributes->get('employee');
        $e ??= $own ? Employee::with($with)->find($own->id) : null;
        // Last-resort showcase fallback picks an arbitrary CURRENT employee, so it must
        // not surface an archived person. The ?emp= and own-record lookups above stay
        // unfiltered — they legitimately resolve a specific (possibly archived) profile.
        $e ??= Employee::active()->with($with)->where('name', 'like', 'Nurul%')->first()
            ?? Employee::active()->with($with)->first();

        $assignedTasks = $e
            ? WorkItem::where('employee_id', $e->id)
                ->whereNotNull('assigned_by_id')
                ->with('assignedBy')
                ->orderByRaw('due_at IS NULL, due_at ASC')
                ->get()
            : collect();

        // Attendance is sensitive: the person themselves, management/HR/director, or one of
        // this person's own managers (primary or dotted-line) may see it — never a random peer.
        // verifierIds() is the same "who manages this requester" source used by leave/claim gates.
        $canSeeAttendance = $e && (
            ($own && $own->id === $e->id)
            || $this->hasTenantRole($request, ['management', 'hr', 'director'])
            || ($own && in_array($own->id, $e->verifierIds(), true))
        );
        $attendance = ($e && $canSeeAttendance)
            ? $e->attendanceRecords()
                ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->orderByDesc('date')
                ->get()
            : collect();

        return array_merge([
            'profile' => $e,
            'canAssign' => $this->hasTenantRole($request, ['manager', 'management', 'hr']),
            // Salary is board + HR only — gates the salary field inside the edit form so the
            // management role can edit everyone without seeing or changing pay (same rule as
            // the directory column and the server-side write guard in EmployeeController).
            'canSeeSalary' => $this->hasTenantRole($request, ['director', 'hr']),
            'assignedTasks' => $assignedTasks,
            'canSeeAttendance' => $canSeeAttendance,
            'attendance' => $attendance,
        ], $this->orgOptions());
    }

    private function assetsData(Request $request): array
    {
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);

        return [
            'assets' => Asset::with('employee')->orderByDesc('status')->orderBy('name')->get(),
            'recipients' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name']) : collect(),
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
                ? Employee::active()->with(['department:id,name', 'positionBand:id,title'])->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color', 'department_id', 'user_id', 'position_id'])
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
}
