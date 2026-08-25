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
            // Not just isToday(): a shift that crosses midnight (in 23:00, out 01:30) has its
            // open record dated *yesterday*, so after midnight the shelf found nothing and told
            // an employee mid-shift they had never clocked in — and posted action=in, opening a
            // second record for the same shift. Prefer the still-open punch, bounded to
            // yesterday-or-today, exactly as ClockService::clockOut() looks it up.
            'today' => $records->first(fn ($r) => $r->clock_in !== null
                && $r->clock_out === null
                && $r->date->toDateString() >= now()->subDay()->toDateString())
                ?? $records->first(fn ($r) => $r->date->isToday()),
            'site' => $employee ? app(ScheduleResolver::class)->resolve($employee, now()) : null,
            // Every geofenced location in the tenant, so the attendance screen's live chip
            // can name the site the staff member is standing in — the same match the server
            // makes on the punch (ScheduleResolver::matchActualSite).
            'geofencedSites' => $employee
                ? app(ScheduleResolver::class)->configuredSites($employee->tenant_id)
                : [],
            // The screen judges lateness itself so a late punch opens the reason drawer in
            // place instead of costing a failed submit. `?? 0` mirrors ClockService::isLate()
            // exactly, so the browser and the server can never disagree about who is late.
            'lateGraceMinutes' => (int) ($employee?->tenant->late_grace_minutes ?? 0),
            'weekRecords' => $weekRecords,
            'earlierRecords' => $earlierRecords,
            'weekWorkedMinutes' => $weekWorkedMinutes,
            'weekBaselineDeltaMinutes' => $weekBaselineDeltaMinutes,
            'lateThisMonth' => $lateThisMonth,
            'offSiteThisMonth' => $offSiteThisMonth,
        ];
    }

    /**
     * Board screen payload: the four columns, plus the people picker roster.
     * Every role may include people on a card they already manage, so the roster
     * ships to everyone; whether the picker is usable on a given card is decided
     * per-card by WorkItemController::canManage() (the drawer's `locked` flag),
     * which is the same check the write-path enforces — no picker shown that
     * then 403s on save.
     */
    private function boardScreenData(Request $request, ?Employee $employee): array
    {
        return [
            'columns' => $this->boardColumns($employee, request('type', 'core')),
            'boardType' => request('type', 'core'),
            'archivedCount' => $employee ? WorkItem::query()
                ->where(fn ($q) => $q->where('employee_id', $employee->id)
                    ->orWhereHas('participants', fn ($p) => $p->whereKey($employee->id)))
                ->whereNotNull('archived_at')
                ->count() : 0,
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
            'people' => $employee
                ? Employee::active()->where('id', '!=', $employee->id)
                    // `nickname` is selected because display_name falls back to the
                    // legal name whenever the column is absent from the row.
                    ->orderBy('name')->get(['id', 'name', 'nickname', 'initials', 'avatar_color'])
                    // `search` is the haystack the card's "add someone" picker filters
                    // on — display name plus legal name, so someone typed by their
                    // full name is found even when the list shows their nickname.
                    ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'initials' => $e->initials, 'color' => $e->avatar_color, 'search' => mb_strtolower($e->display_name.' '.$e->name)])
                    ->values()
                : collect(),
        ];
    }

    private function boardColumns(?Employee $employee, string $type = 'core'): array
    {
        // One board holds every work type (assignments, tasks, adhoc). The `?type`
        // param only sets the client-side filter's starting focus — it no longer
        // splits the data across pages. Filtering happens live in the browser.
        // A card leaves the Done column once archived (archived_at set) — either
        // explicitly via WorkItemController::archive(), or automatically a day after
        // it was marked done (ArchiveDoneWorkItems, scheduled hourly). Reopening puts
        // it back at todo, so an archived card is never stuck.
        // A card belongs to one owner, but may also include participants — the same
        // shared card then shows on each included person's board. Load both: cards I
        // own, plus cards I'm a participant on.
        $items = $employee ? WorkItem::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->whereKey($employee->id)))
            ->whereNull('archived_at')
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
                // Same archived-card exclusion as the personal board — the team view
                // loads EVERY employee's items in one request, so the bound matters more.
                'workItems' => fn ($q) => $q
                    ->whereNull('archived_at')
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
                'owner_name' => $e->display_name,
                'owner_initials' => $e->initials,
                'owner_avatar_color' => $e->avatar_color,
            ])->all();
        })->values();

        // Per-person aggregates.
        $teamPeople = $employees->map(function ($e) use ($today) {
            $items = $e->workItems;

            return [
                'id' => $e->id,
                'name' => $e->display_name,
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
            // Same three roles WorkItemController::ASSIGNER_ROLES and the profile
            // page's own "Assign task" button check — director included, via
            // hasTenantRole()'s effectiveRole() fold.
            'canAssign' => $this->hasTenantRole($request, ['manager', 'management', 'hr']),
            // Every active employee, tenant-wide, minus the viewer themselves —
            // deliberately NOT the DataScope-restricted $employees above, and
            // deliberately not limited to people already in $teamPeople (a
            // brand-new hire with zero tasks must still be assignable). This
            // matches assign()'s own authorization boundary exactly: role + tenant
            // + not-archived, no DataScope check — see the design doc's "Data"
            // section for why a narrower roster here would just be confusing.
            'assignableEmployees' => Employee::active()
                ->where('id', '!=', $self?->id)
                ->orderBy('name')
                ->get(['id', 'name', 'nickname', 'initials', 'avatar_color'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'initials' => $e->initials, 'color' => $e->avatar_color])
                ->values(),
        ];
    }

    /**
     * Claims screen data, role-aware. Every viewer gets their own claims, approval chain
     * and medical-cap figures for the new-claim sheet. Approvers (manager/management/hr)
     * additionally get the two-step verify/approve queues (see RoutesApprovalsByReportingLine
     * — immediate superior verifies, then management approves) for the "Approvals" tab, and
     * management/hr get the full company ledger for the "All claims" tab. Non-privileged
     * viewers never receive the queue/company keys at all, so those tabs cannot leak.
     *
     * The `claim-approvals` slug renders this same screen (defaulting to the Approvals tab)
     * so existing deep links keep working — see AppController::screen().
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

        // An approver has a verify/approve queue; a privileged viewer (management/hr) also
        // sees the company-wide ledger. A plain employee is neither, and gets no extra keys.
        $isApprover = $this->hasTenantRole($request, ['manager', 'management', 'hr']);
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);

        $data = [
            'myClaims' => $myClaims,
            'approvalChain' => $this->approvalChain($employee),
            'medicalCap' => (float) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'claims.medical_cap'),
            'medicalUsedYtd' => $medicalUsedYtd,
            'isApprover' => $isApprover,
            'privileged' => $privileged,
        ];

        if ($isApprover) {
            $data['claimsToVerify'] = $this->scopeToVerify(Claim::with('employee'), $request)->latest('date')->get();
            $data['claimsToApprove'] = $this->scopeToApprove(Claim::with(['employee', 'verifiedBy']), $request)->latest('date')->get();
        }

        if ($privileged) {
            // Company-wide claims view, management/hr only. Totals come from one grouped
            // aggregate query (not summed in PHP), and the claim list is capped at the
            // latest 50 rows on purpose so this screen doesn't grow heavier forever.
            $data['claimTotals'] = Claim::query()->selectRaw('status, COUNT(*) as count, SUM(amount) as total')->groupBy('status')->get()->keyBy('status');
            $data['allClaims'] = Claim::with('employee')->latest('date')->take(50)->get();
        }

        return $data;
    }

    private function leaveData(Request $request, ?Employee $employee): array
    {
        // Two-step gate (see RoutesApprovalsByReportingLine): the immediate superior sees
        // their reports' submitted requests to verify; management sees verified ones to approve.
        // Both queues eager-load the requester's balances: the review row states what the
        // person is left with if you approve, which is the number an approver needs and
        // which used to be absent from the queue entirely.
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
            // HR reports straight to the directors, so their own requests open already
            // verified. The Apply form must promise the right chain, not the generic one.
            'leaveSkipsVerification' => $this->skipsVerification($request),
            'leaveToVerify' => $this->scopeToVerify(LeaveRequest::with(['employee.leaveBalances.leaveType', 'leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id']), $request)->latest()->get(),
            'leaveToApprove' => $this->scopeToApprove(LeaveRequest::with(['employee.leaveBalances.leaveType', 'leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id']), $request)->latest()->get(),
            // active() owner: a since-archived person holds no live leave — drop their
            // approved requests from the team-leave widget (mirrors the approval queues).
            // Ongoing or upcoming only — date_to >= today, soonest first — not "most
            // recently approved", which surfaced leave that had already finished.
            'teamLeave' => LeaveRequest::with('employee')->where('status', 'approved')
                ->where('date_to', '>=', now()->toDateString())
                ->whereHas('employee', fn ($q) => $q->active())
                ->orderBy('date_from')->take(6)->get(),
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
