<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Attendance\ScheduleResolver;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\StatutoryRate;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;

/**
 * Attendance, board, leave, claims and payroll screen data for
 * AppController::screen(). Split out of AppController purely for file size —
 * every method still runs on the controller instance ($this), so the
 * RoutesApprovalsByReportingLine queue scopes keep working.
 */
trait BuildsWorkData
{
    /**
     * The personal attendance screen shows the current-week card plus a short
     * history, so load a bounded recent window — never the full ever-growing
     * record set (~260 rows/year per person on the highest-frequency screen).
     * Today's record is derived from the loaded window instead of a second query.
     */
    private function attendanceData(?Employee $employee): array
    {
        $records = $employee
            ? $employee->attendanceRecords()
                ->where('date', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('date')
                ->get()
            : collect();

        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $weekRecords = $records->filter(
            fn ($r) => $r->date->gte($startOfWeek) && $r->date->lte($endOfWeek)
        )->values();

        $earlierRecords = $records->filter(
            fn ($r) => $r->date->lt($startOfWeek)
        )->values();

        $weekWorkedMinutes = (int) $weekRecords->sum('worked_minutes');

        /**
         * weekBaselineDeltaMinutes uses expected_min_hours of completed days (clock_out !== null),
         * which is the same expectation the record's flags were raised from, so the delta cannot
         * drift from the flags, and no schedule needs re-resolving.
         */
        $weekExpectedMinutes = (int) $weekRecords
            ->filter(fn ($r) => $r->clock_out !== null && $r->expected_min_hours !== null)
            ->sum(fn ($r) => (int) round((float) $r->expected_min_hours * 60));

        $weekBaselineDeltaMinutes = $weekWorkedMinutes - $weekExpectedMinutes;

        $lateThisMonth = (int) $records->filter(
            fn ($r) => $r->date->gte($startOfMonth) && $r->date->lte($endOfMonth) && $r->status === 'late'
        )->count();

        $offSiteThisMonth = (int) $records->filter(
            fn ($r) => $r->date->gte($startOfMonth)
                && $r->date->lte($endOfMonth)
                && is_array($r->flags)
                && (in_array('out_of_radius_in', $r->flags, true) || in_array('out_of_radius_out', $r->flags, true))
        )->count();

        return [
            'records' => $records,
            'today' => $records->first(fn ($r) => $r->date->isToday()),
            'site' => $employee ? app(ScheduleResolver::class)->resolve($employee, now()) : null,
            // Every geofenced location in the tenant, so the attendance screen's live chip
            // can name the site the staff member is standing in — the same match the server
            // makes on the punch (ScheduleResolver::matchActualSite).
            'geofencedSites' => $employee
                ? app(ScheduleResolver::class)->configuredSites($employee->tenant_id)
                : [],
            'weekRecords' => $weekRecords,
            'earlierRecords' => $earlierRecords,
            'weekWorkedMinutes' => $weekWorkedMinutes,
            'weekBaselineDeltaMinutes' => $weekBaselineDeltaMinutes,
            'lateThisMonth' => $lateThisMonth,
            'offSiteThisMonth' => $offSiteThisMonth,
        ];
    }

    /**
     * Board screen payload: the four columns, plus (for privileged roles) the
     * people picker roster. Only manager / management / hr may include people on a
     * card, mirroring assign() and WorkItemController::syncParticipants(), so only
     * they receive `people` and `canAssignPeople` = true. Uses the raw tenant role
     * (not effectiveRole) so the picker's visibility matches what the write-path
     * will actually accept — no picker shown that then 403s on save.
     */
    private function boardScreenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $canAssignPeople = in_array($role, ['manager', 'management', 'hr'], true);

        return [
            'columns' => $this->boardColumns($employee, request('type', 'core')),
            'boardType' => request('type', 'core'),
            'canAssignPeople' => $canAssignPeople,
            // A mention notification lands here as /app/board?card={id}. No access
            // check happens server-side — the value is only relayed to the client,
            // which opens it through the same authorized GET /app/board/{workItem}
            // the drawer already uses. That endpoint's authorizeAccess() is what
            // actually decides visibility, so an inaccessible or nonexistent id
            // just renders the board normally instead of 403ing the whole screen.
            'deepLinkCardId' => $request->filled('card') && ctype_digit((string) $request->query('card'))
                ? (int) $request->query('card')
                : null,
            // Active projects for the card editor's optional project picker. Tenant
            // scope is applied automatically by BelongsToTenant in a request context.
            'projects' => Project::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'people' => $canAssignPeople && $employee
                ? Employee::active()->where('id', '!=', $employee->id)
                    ->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color'])
                    ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name, 'initials' => $e->initials, 'color' => $e->avatar_color])
                    ->values()
                : collect(),
        ];
    }

    private function boardColumns(?Employee $employee, string $type = 'core'): array
    {
        // One board holds every work type (assignments, tasks, adhoc). The `?type`
        // param only sets the client-side filter's starting focus — it no longer
        // splits the data across pages. Filtering happens live in the browser.
        // Done cards older than 30 days are history, not work — leave them out so
        // the daily-driver board doesn't grow heavier forever.
        // A card belongs to one owner, but may also include participants — the same
        // shared card then shows on each included person's board. Load both: cards I
        // own, plus cards I'm a participant on.
        $items = $employee ? WorkItem::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->whereKey($employee->id)))
            ->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('updated_at', '>=', now()->subDays(30)))
            ->with(['assignedBy', 'participants', 'projectRef'])->withCount('comments')
            ->orderBy('sort_order')->orderBy('id')->get() : collect();
        $cols = [
            'todo' => ['title' => 'To Do', 'cards' => collect()],
            'prog' => ['title' => 'In Progress', 'cards' => collect()],
            'review' => ['title' => 'In Review', 'cards' => collect()],
            'done' => ['title' => 'Done', 'cards' => collect()],
        ];
        foreach ($items as $i) {
            if (isset($cols[$i->status])) {
                $cols[$i->status]['cards']->push($i);
            }
        }

        return $cols;
    }

    /**
     * Read-only company-wide task board for management / HR / immediate superiors:
     * flat rows (one per work item with owner info) and per-person aggregates.
     * People with no work items are omitted so the view stays scannable.
     */
    private function teamBoardData(Request $request): array
    {
        // Data scope: a branch/department-restricted manager only sees their slice of the
        // company board, not every employee's work items (AK-AUTHZ-01).
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');

        $employees = app(DataScope::class)->applyToEmployees(Employee::active(), $scope, $self)
            ->with([
                'positionBand', 'department',
                // Same 30-day done-card window as the personal board — the team view
                // loads EVERY employee's items in one request, so the bound matters more.
                'workItems' => fn ($q) => $q
                    ->where(fn ($w) => $w->where('status', '!=', 'done')->orWhere('updated_at', '>=', now()->subDays(30)))
                    // participants + projectRef are also loaded here (not just assignedBy) so
                    // partials.work-card, shared with the personal board, never lazy-loads a
                    // relation while painting every employee's lane in one request.
                    ->with(['assignedBy', 'participants', 'projectRef'])->withCount('comments')->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn ($e) => $e->workItems->isNotEmpty());

        $today = today();

        // Flat rows: one entry per work item, carrying owner info.
        // Ordered by owner name (from the query), then sort_order, then id (from the eager load).
        $teamRows = $employees->flatMap(function ($e) {
            return $e->workItems->map(fn ($item) => [
                'item' => $item,
                'owner_id' => $e->id,
                'owner_name' => $e->name,
                'owner_initials' => $e->initials,
                'owner_avatar_color' => $e->avatar_color,
            ])->all();
        })->values();

        // Per-person aggregates.
        $teamPeople = $employees->map(function ($e) use ($today) {
            $items = $e->workItems;

            return [
                'id' => $e->id,
                'name' => $e->name,
                'initials' => $e->initials,
                'avatar_color' => $e->avatar_color,
                'position' => $e->positionBand?->title,
                'department' => $e->department?->name,
                'open' => $items->where('status', '!=', 'done')->count(),
                'overdue' => $items->filter(fn ($i) => $i->due_at && $i->status !== 'done' && $i->due_at->lt($today))->count(),
                'blocked' => $items->filter(fn ($i) => in_array('blocked', $i->labels ?? [], true))->count(),
                'in_review' => $items->where('status', 'review')->count(),
                'done' => $items->where('status', 'done')->count(),
            ];
        })->values();

        return [
            'teamRows' => $teamRows,
            'teamPeople' => $teamPeople,
            'teamOpenTotal' => $teamPeople->sum('open'),
            'teamPeopleCount' => $teamPeople->count(),
        ];
    }

    /**
     * Personal-only claims data for the `claims` screen: the viewer's own claims, their
     * approval chain and the medical-cap figures the new-claim drawer needs. Company-wide
     * data and the verify/approve queues live in claimApprovalsData() instead — see there.
     */
    private function claimsData(Request $request, ?Employee $employee): array
    {
        $myClaims = $employee?->claims()->latest('date')->get() ?? collect();

        // Medical allowance consumed this calendar year (all non-rejected medical claims),
        // so the form can show what's left against the annual cap.
        $medicalUsedYtd = (float) $myClaims
            ->where('type', 'medical')
            ->where('status', '!=', 'rejected')
            ->filter(fn (Claim $c) => $c->date?->year === now()->year)
            ->sum('amount');

        return [
            'myClaims' => $myClaims,
            'approvalChain' => $this->approvalChain($employee),
            'medicalCap' => (float) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'claims.medical_cap'),
            'medicalUsedYtd' => $medicalUsedYtd,
        ];
    }

    /**
     * Company-wide claims data for the `claim-approvals` screen: the two-step approval
     * queues (see RoutesApprovalsByReportingLine — immediate superior verifies, then
     * management approves) plus, for management/hr, the full company ledger.
     */
    private function claimApprovalsData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);

        return [
            'privileged' => $privileged,
            'claimsToVerify' => $this->scopeToVerify(Claim::with('employee'), $request)->latest('date')->get(),
            'claimsToApprove' => $this->scopeToApprove(Claim::with(['employee', 'verifiedBy']), $request)->latest('date')->get(),
            // Company-wide claims view, management/hr only. Totals come from one grouped
            // aggregate query (not summed in PHP), and the claim list is capped at the
            // latest 50 rows on purpose so this screen doesn't grow heavier forever.
            'claimTotals' => $privileged
                ? Claim::query()->selectRaw('status, COUNT(*) as count, SUM(amount) as total')->groupBy('status')->get()->keyBy('status')
                : collect(),
            'allClaims' => $privileged
                ? Claim::with('employee')->latest('date')->take(50)->get()
                : collect(),
        ];
    }

    private function leaveData(Request $request, ?Employee $employee): array
    {
        // Two-step gate (see RoutesApprovalsByReportingLine): the immediate superior sees
        // their reports' submitted requests to verify; management sees verified ones to approve.
        // Approval chain (verifier[s] + management approver pool) shown up front so the
        // applicant knows who signs off before they submit. Also feeds the pending-verify
        // name in "My requests" timelines.
        $chain = $this->approvalChain($employee);

        return [
            'balances' => $employee?->leaveBalances()->with('leaveType')->get() ?? collect(),
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'myRequests' => $employee?->leaveRequests()->with(['leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id'])->latest()->get() ?? collect(),
            'approvalChain' => $chain,
            'leaveVerifiers' => $chain['verifiers'],
            'leaveToVerify' => $this->scopeToVerify(LeaveRequest::with(['employee', 'leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id']), $request)->latest()->get(),
            'leaveToApprove' => $this->scopeToApprove(LeaveRequest::with(['employee', 'leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id']), $request)->latest()->get(),
            // active() owner: a since-archived person holds no live leave — drop their
            // approved requests from the team-leave widget (mirrors the approval queues).
            'teamLeave' => LeaveRequest::with('employee')->where('status', 'approved')
                ->whereHas('employee', fn ($q) => $q->active())
                ->latest()->take(6)->get(),
            'holidays' => PublicHoliday::orderBy('date')->get(),
        ];
    }

    private function payrollData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);

        // Employee's own issued payslips — finalized runs only.
        $myPayslips = $employee
            ? $employee->payslips()->with('payrollRun')->get()
                ->filter(fn ($p) => $p->payrollRun?->status === 'finalized')
                ->sortByDesc(fn ($p) => $p->payrollRun->period)->values()
            : collect();

        // A specific payslip detail: own (finalized) for everyone, any for privileged.
        $selectedPayslip = null;
        if ($request->filled('payslip')) {
            $candidate = Payslip::with(['employee', 'payrollRun'])->find($request->query('payslip'));
            if ($candidate) {
                $ownIt = $employee && $candidate->employee_id === $employee->id;
                $visible = $privileged || ($ownIt && $candidate->payrollRun?->status === 'finalized');
                $selectedPayslip = $visible ? $candidate : null;
            }
        }

        if (! $privileged) {
            return [
                'privileged' => false,
                'myPayslips' => $myPayslips,
                'selectedPayslip' => $selectedPayslip,
                'runs' => collect(),
                'activeRun' => null,
                'salaryEmployees' => collect(),
            ];
        }

        $activeRun = $request->filled('run')
            ? PayrollRun::with('payslips.employee')->find($request->query('run'))
            : PayrollRun::with('payslips.employee')->orderByDesc('period')->first();

        return [
            'privileged' => true,
            'myPayslips' => $myPayslips,
            'selectedPayslip' => $selectedPayslip,
            'runs' => PayrollRun::withCount('payslips')->orderByDesc('period')->get(),
            'activeRun' => $activeRun,
            'salaryEmployees' => Employee::active()->with('salaryStructure')->orderBy('name')->get(),
            'rates' => StatutoryRate::merged(),
        ];
    }
}
