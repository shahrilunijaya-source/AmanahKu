<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Attendance\ScheduleResolver;
use App\Models\Claim;
use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\IndividualTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollItem;
use App\Models\PayrollNotice;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\PayrollTp1Claim;
use App\Models\Payslip;
use App\Models\Project;
use App\Models\Scopes\ParentOnly;
use App\Models\TimesheetCategory;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Services\FeatureManager;
use App\Services\GoogleCalendarClient;
use App\Services\Payroll\PayrollReadiness;
use App\Support\Calendar\CalendarSyncStatus;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
            // The Google Calendar control (status, Sync now, issues). Null hides it.
            'calendarSync' => app(GoogleCalendarClient::class)->configured() && $request->user()
                ? CalendarSyncStatus::for($request->user(), $employee?->tenant_id)
                : null,
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
        // CR-04: a card I review sits on my board too, under the Reviewing chip.
        $items = $employee ? WorkItem::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhere('reviewer_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->whereKey($employee->id)))
            ->whereNull('archived_at')
            ->with(['assignedBy', 'participants', 'projectRef', 'children'])->withCount('comments')
            ->orderBy('sort_order')->orderBy('id')->get() : collect();

        // A subtask handed to someone other than its parent's owner shows on THAT
        // person's board too, as an ordinary card (see partials.work-card's "Subtask
        // of ..." line) — bypasses ParentOnly on purpose, the one other place besides
        // WorkItem::children() that wants child rows. A subtask left at the parent's
        // own owner never duplicates onto their board; it stays visible only through
        // the parent's "n/m" badge, same as before this feature.
        if ($employee) {
            $assignedChildren = WorkItem::withoutGlobalScope(ParentOnly::class)
                ->whereNotNull('parent_id')
                ->where('employee_id', $employee->id)
                ->whereNull('archived_at')
                ->whereHas('parent', fn ($q) => $q->where('employee_id', '!=', $employee->id))
                ->with(['assignedBy', 'participants', 'projectRef', 'parent'])->withCount('comments')
                ->orderBy('sort_order')->orderBy('id')->get();
            $items = $items->concat($assignedChildren);
        }
        // `assigned` is the column badge: cards this person owns, never the ones they
        // help on or review (contracts/roles.md, counters count Assigned only).
        $cols = [
            'todo' => ['title' => 'To Do', 'cards' => collect(), 'assigned' => 0],
            'prog' => ['title' => 'In Progress', 'cards' => collect(), 'assigned' => 0],
            'review' => ['title' => 'In Review', 'cards' => collect(), 'assigned' => 0],
            'done' => ['title' => 'Done', 'cards' => collect(), 'assigned' => 0],
        ];
        foreach ($items as $i) {
            if (isset($cols[$i->status])) {
                $cols[$i->status]['cards']->push($i);
                if ($employee && $i->employee_id === $employee->id) {
                    $cols[$i->status]['assigned']++;
                }
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
                    ->with(['assignedBy', 'participants', 'projectRef', 'children'])->withCount('comments')->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('name')
            ->get();

        // CR-04: the cards each person helps on, is kept informed of, or reviews.
        // Shown in their lane with the role label and reported beside the counters
        // ("helping on 3 / reviewing 2"), never inside them (contracts/roles.md).
        $ids = $employees->pluck('id')->all();
        $tagged = WorkItem::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereIn('reviewer_id', $ids)
                ->orWhereHas('participants', fn ($p) => $p->whereIn('employees.id', $ids)))
            ->with(['assignedBy', 'participants', 'projectRef', 'children'])->withCount('comments')
            ->orderBy('sort_order')->orderBy('id')->get();
        $taggedFor = fn (Employee $e) => $tagged
            ->map(fn (WorkItem $i) => ['item' => $i, 'role' => $i->roleFor($e->id)])
            ->filter(fn (array $row) => in_array($row['role'], ['helper', 'fyi', 'reviewer'], true))
            ->values();
        $employees = $employees
            ->each(fn (Employee $e) => $e->setAttribute('tagged_rows', $taggedFor($e)))
            ->filter(fn ($e) => $e->workItems->isNotEmpty() || $e->tagged_rows->isNotEmpty());

        $today = today();

        // Flat rows: one entry per work item, carrying owner info; a person's tagged
        // and reviewed cards follow their own, keyed to their lane with the role.
        // Ordered by owner name (from the query), then sort_order, then id (from the eager load).
        $teamRows = $employees->flatMap(function ($e) {
            $own = $e->workItems->map(fn ($item) => [
                'item' => $item,
                'role' => 'assigned',
                'owner_id' => $e->id,
                'owner_name' => $e->display_name,
                'owner_initials' => $e->initials,
                'owner_avatar_color' => $e->avatar_color,
            ]);
            $extra = $e->tagged_rows->map(fn (array $row) => [
                'item' => $row['item'],
                'role' => $row['role'],
                'owner_id' => $e->id,
                'owner_name' => $e->display_name,
                'owner_initials' => $e->initials,
                'owner_avatar_color' => $e->avatar_color,
            ]);

            return $own->concat($extra)->all();
        })->values();

        // Per-person aggregates. The four counters count Assigned cards only.
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
                'overdue' => $items->filter(fn ($i) => $i->due_at && $i->status !== 'done' && $i->due_at->lt($today) && $i->type !== 'event' && ! $i->cancelled_at)->count(),
                'blocked' => $items->filter(fn ($i) => in_array('blocked', $i->labels ?? [], true))->count(),
                'in_review' => $items->where('status', 'review')->count(),
                'done' => $items->where('status', 'done')->count(),
                'helping' => $e->tagged_rows->where('role', 'helper')->filter(fn (array $r) => $r['item']->status !== 'done')->count(),
                'reviewing' => $e->tagged_rows->where('role', 'reviewer')->filter(fn (array $r) => $r['item']->status !== 'done')->count(),
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
            // The assign form asks for the category the same way the card drawer does, so
            // work handed to someone else arrives costed. `requires_project` is what lets
            // the form show or hide its project picker without a round trip.
            'assignCategories' => TimesheetCategory::where('is_active', true)
                ->whereNotIn('name', TimesheetCategory::generatedNames())
                ->orderBy('sort')->orderBy('name')->get()
                ->map(fn (TimesheetCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'requires_project' => (bool) $c->requires_project,
                ])->values(),
            // `category_ids` narrows that picker. Read from the project end, exactly as
            // WorkItem::projectOptions() reads it server-side: a project tagged with
            // nothing is offered under every category, and a category nobody has tagged a
            // project with is offered only those. Reading it from the category end instead
            // would open every project up to an untagged category — see the docblock on
            // projectOptions() for why that quietly switches the pairing guard off.
            'assignProjects' => Project::with('categories:id')
                ->where('is_active', true)
                ->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'category_ids' => $p->categories->pluck('id')->all(),
                ])->values(),
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
    /**
     * HR's "On behalf" picker on the Apply tabs. Returns the person the form is currently
     * set up for (`?for=<employee id>`, HR only, defaults to the viewer) and the staff list
     * to pick from — empty for everyone who is not HR, so the picker never renders.
     *
     * @return array{applyFor: ?Employee, onBehalfStaff: Collection}
     */
    private function onBehalfData(Request $request, ?Employee $employee): array
    {
        if (! $this->hasTenantRole($request, ['hr'])) {
            return ['applyFor' => $employee, 'onBehalfStaff' => collect()];
        }

        $staff = Employee::active()->orderBy('name')->get();
        $for = $request->query('for');
        $target = $for ? $staff->firstWhere('id', (int) $for) : null;

        return ['applyFor' => $target ?? $employee, 'onBehalfStaff' => $staff];
    }

    /**
     * The Approvals tab's filter bar, read off the query string: `period` is `all` (no
     * date filter, the default so nothing pending is ever hidden) or `range`, between
     * `from` and `to` (Y-m-d, this month when missing or unreadable). `q` is free text.
     *
     * @return array{period: string, from: string, to: string, q: string}
     */
    private function approvalFilters(Request $request): array
    {
        $date = fn (string $key, Carbon $fallback) => rescue(
            fn () => Carbon::createFromFormat('!Y-m-d', (string) $request->query($key)),
            $fallback,
            false,
        )->toDateString();

        $from = $date('from', now()->startOfMonth());
        $to = $date('to', now()->endOfMonth());

        return [
            'period' => $request->query('period') === 'range' ? 'range' : 'all',
            'from' => min($from, $to),
            'to' => max($from, $to),
            'q' => trim((string) $request->query('q')),
        ];
    }

    /**
     * Narrow an approvals query by the filter bar. A request is in the date range when
     * its own span overlaps it (a claim's span is its one transaction date), and the
     * search matches the requester's name or staff id, or any of $textColumns
     * (`relation.column` searches a related model).
     *
     * @param  array{period: string, from: string, to: string, q: string}  $filters
     * @param  list<string>  $textColumns
     */
    private function applyApprovalFilters(Builder $query, array $filters, string $startColumn, string $endColumn, array $textColumns): Builder
    {
        $like = '%'.addcslashes($filters['q'], '%_\\').'%';

        return $query
            ->when($filters['period'] === 'range', fn (Builder $q) => $q
                ->whereDate($startColumn, '<=', $filters['to'])
                ->whereDate($endColumn, '>=', $filters['from']))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($like, $textColumns) {
                $w->whereHas('employee', fn (Builder $e) => $e
                    ->where('name', 'like', $like)
                    ->orWhere('nickname', 'like', $like)
                    ->orWhere('staff_id', 'like', $like));
                foreach ($textColumns as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $relatedColumn] = explode('.', $column, 2);
                        $w->orWhereRelation($relation, $relatedColumn, 'like', $like);
                    } else {
                        $w->orWhere($column, 'like', $like);
                    }
                }
            }));
    }

    private function claimsData(Request $request, ?Employee $employee): array
    {
        ['applyFor' => $applyFor, 'onBehalfStaff' => $onBehalfStaff] = $this->onBehalfData($request, $employee);
        $myClaims = $employee?->claims()->latest('date')->get() ?? collect();

        // Medical allowance consumed this calendar year (all non-rejected medical claims),
        // so the form can show what's left against the annual cap. Counted for the person
        // the form is filing FOR, which is the viewer unless HR picked someone.
        $medicalUsedYtd = (float) ($applyFor?->claims()
            ->where('type', 'medical')
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereYear('date', now()->year)
            ->sum('amount') ?? 0);

        // An approver has a verify/approve queue; a privileged viewer (management/hr) also
        // sees the company-wide ledger. A plain employee is neither, and gets no extra keys.
        $isApprover = $this->hasTenantRole($request, ['manager', 'management', 'hr']);
        $privileged = $this->hasTenantRole($request, ['management', 'hr']);
        // A plain manager only ever recommends — scopeToApprove() closes for them. Naming
        // their tab "Approvals" would overstate what they can do, so the label follows the
        // role: "To verify" for a recommender, "Approvals" for the final-approval tier.
        $givesFinalApproval = $this->hasTenantRole($request, Permissions::FINAL_APPROVAL_ROLES);

        $data = [
            'myClaims' => $myClaims,
            'approvalChain' => $this->approvalChain($applyFor),
            'applyFor' => $applyFor,
            'onBehalfStaff' => $onBehalfStaff,
            'applySkipsVerification' => $this->skipsVerification($request, $applyFor),
            'medicalCap' => (float) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'claims.medical_cap'),
            'medicalUsedYtd' => $medicalUsedYtd,
            'isApprover' => $isApprover,
            'privileged' => $privileged,
            'givesFinalApproval' => $givesFinalApproval,
            // Whether an approved claim's "pays next run" payroll suffix means anything for
            // this tenant: payroll can be switched off, and the claims screen must not
            // promise a payroll run that will never happen.
            'payrollAllowed' => app(FeatureManager::class)->screenAllowed(app(CurrentTenant::class)->get(), 'payroll'),
        ];

        if ($isApprover) {
            // Every list on the Approvals tab reads the same filter bar (period, dates, search).
            $filters = $this->approvalFilters($request);
            $filter = fn (Builder $q) => $this->applyApprovalFilters($q, $filters, 'date', 'date', ['title', 'reason', 'type']);
            $data['approvalFilters'] = $filters;
            $data['claimsToVerify'] = $filter($this->scopeToVerify(Claim::with('employee'), $request))->latest('date')->get();
            $data['claimsToApprove'] = $filter($this->scopeToApprove(Claim::with(['employee', 'verifiedBy']), $request))->latest('date')->get();
            // Every settled claim from the people this viewer can act on, whoever decided it.
            // Paid counts as approved: payroll reimbursing it does not un-approve it.
            $settled = fn (array $statuses) => $filter($this->scopeReviewable(Claim::with(['employee', 'verifiedBy']), $request))
                ->whereIn('status', $statuses)->latest('date')->get();
            $data['claimsApproved'] = $settled(['approved', 'paid']);
            $data['claimsRejected'] = $settled(['rejected']);
            $data['claimsCancelled'] = $settled(['cancelled']);
        }

        if ($privileged) {
            // Company-wide claims view, management/hr only. The claim list is capped at
            // the latest 50 rows on purpose so this screen doesn't grow heavier forever.
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
        ['applyFor' => $applyFor, 'onBehalfStaff' => $onBehalfStaff] = $this->onBehalfData($request, $employee);
        $chain = $this->approvalChain($applyFor);

        // Every list on the Approvals tab reads the same filter bar (period, dates, search).
        $filters = $this->approvalFilters($request);
        $filter = fn (Builder $q) => $this->applyApprovalFilters($q, $filters, 'date_from', 'date_to', ['reason', 'leaveType.name']);
        $actors = ['leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id'];
        $settledLeave = fn (array $statuses) => $filter($this->scopeReviewable(LeaveRequest::with(['employee', ...$actors]), $request))
            ->whereIn('status', $statuses)->latest('date_from')->get();

        return [
            'balances' => $employee?->leaveBalances()->with('leaveType')->get() ?? collect(),
            // The Apply tab's own copy of the balances: the viewer's, unless HR is filing for
            // someone else — then that person's, so eligibility and "days left" are theirs.
            'applyBalances' => $applyFor?->leaveBalances()->with('leaveType')->get() ?? collect(),
            'applyFor' => $applyFor,
            'onBehalfStaff' => $onBehalfStaff,
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'myRequests' => $employee?->leaveRequests()->with(['leaveType', 'verifiedBy:id,name,position_id', 'approvedBy:id,name,position_id', 'rejectedBy:id,name,position_id'])->latest()->get() ?? collect(),
            // Where an HR-granted quota (Replacement) came from. Without this the days
            // appear in the balance with nothing saying which rest day earned them.
            'myLeaveGrants' => $employee?->leaveGrants()->with(['leaveType', 'grantedBy'])->latest('id')->get() ?? collect(),
            'approvalChain' => $chain,
            'leaveVerifiers' => $chain['verifiers'],
            // HR and the directors have nobody above them, so their own requests open already
            // verified. The Apply form must promise the right chain, not the generic one.
            'leaveSkipsVerification' => $this->skipsVerification($request, $applyFor),
            // Names the Approvals tab for what this viewer can actually do — see the note
            // on $givesFinalApproval in the claims builder.
            'givesFinalApproval' => $this->hasTenantRole($request, Permissions::FINAL_APPROVAL_ROLES),
            'leaveToVerify' => $filter($this->scopeToVerify(LeaveRequest::with(['employee.leaveBalances.leaveType', ...$actors]), $request))->latest()->get(),
            'leaveToApprove' => $filter($this->scopeToApprove(LeaveRequest::with(['employee.leaveBalances.leaveType', ...$actors]), $request))->latest()->get(),
            // Every settled request from the people this viewer can act on, whoever decided it.
            'leaveApproved' => $settledLeave(['approved']),
            'leaveRejected' => $settledLeave(['rejected']),
            'leaveCancelled' => $settledLeave(['cancelled']),
            'approvalFilters' => $filters,
            // Gates the tab itself. Deliberately not "is anything pending" — see
            // canReviewAnything: a cleared queue must not take the history with it.
            'leaveCanReview' => $this->canReviewAnything($request),
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

        // Employee's own issued payslips — published runs only (spec F13).
        $myPayslips = $employee
            ? $employee->payslips()->with('payrollRun')->get()
                ->filter(fn ($p) => (bool) $p->payrollRun?->isPublished())
                ->sortByDesc(fn ($p) => $p->payrollRun->period)->values()
            : collect();
        $myEaYears = $myPayslips->map(fn ($p) => (int) substr((string) $p->payrollRun?->period, 0, 4))->filter()->unique()->sortDesc()->values()->all();

        // A specific payslip detail: own (published) for everyone, any for privileged.
        $selectedPayslip = null;
        if ($request->filled('payslip')) {
            $candidate = Payslip::with(['employee', 'payrollRun', 'lines'])->find($request->query('payslip'));
            if ($candidate) {
                $ownIt = $employee && $candidate->employee_id === $employee->id;
                $visible = $privileged || ($ownIt && (bool) $candidate->payrollRun?->isPublished());
                $selectedPayslip = $visible ? $candidate : null;
            }
        }

        if (! $privileged) {
            return [
                'privileged' => false,
                'isManagementTier' => false,
                'myPayslips' => $myPayslips,
                'myEaYears' => $myEaYears,
                'selectedPayslip' => $selectedPayslip,
                'runs' => collect(),
                'activeRun' => null,
                'salaryEmployees' => collect(),
                'finalPayCandidates' => collect(),
                'payrollItems' => collect(),
                'currentPeriod' => now()->format('Y-m'),
                'fixedTransactions' => collect(),
                'fixedTransactionItems' => collect(),
                'individualTransactionsForActiveRun' => collect(),
                'itxPeriod' => now()->format('Y-m'),
                'itxCycle' => 'month_end',
                'itxCycleLocked' => false,
                'itxTransactions' => collect(),
                'itxPeriodFinalized' => false,
                'itxPeriodHasDraftRun' => false,
                'readinessEmployer' => [],
                'readinessCompanyWarnings' => [],
                'payrollSubmissions' => collect(),
                'payrollNotices' => collect(),
                'payslipAckOn' => app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'payroll.payslip_acknowledgement'),
                'payslipAckOutstanding' => [],
                'readinessRows' => [],
                'readinessBlockingCount' => 0,
                'payrollWizard' => null,
                'wizardResultRun' => null,
            ];
        }

        $activeRun = $request->filled('run')
            ? PayrollRun::with('payslips.employee', 'payslips.lines')->find($request->query('run'))
            : PayrollRun::with('payslips.employee', 'payslips.lines')->orderByDesc('period')->first();

        $readiness = app(PayrollReadiness::class);
        $readinessTenant = app(CurrentTenant::class)->get();
        $readinessEmployer = $readinessTenant ? $readiness->employerGaps($readinessTenant) : [];
        $readinessRows = $readinessTenant ? $readiness->employeeRows($readinessTenant) : [];
        $readinessCompanyWarnings = $readinessTenant ? $readiness->companyWarnings($readinessTenant) : [];

        return [
            'privileged' => true,
            // Spec F2: what still blocks a run, shown above the create form.
            'readinessEmployer' => $readinessEmployer,
            'readinessCompanyWarnings' => $readinessCompanyWarnings,
            'readinessRows' => $readinessRows,
            'readinessBlockingCount' => count($readinessEmployer) + count(array_filter($readinessRows, fn (array $r) => $r['blocking'] !== [])),
            // Deleting a FINALIZED run is a step above the usual HR/management payroll
            // gate — see PayrollController::destroyRun() and Permissions::MANAGEMENT_TIER.
            'isManagementTier' => $this->hasTenantRole($request, Permissions::MANAGEMENT_TIER),
            'myPayslips' => $myPayslips,
            'myEaYears' => $myEaYears,
            'selectedPayslip' => $selectedPayslip,
            'runs' => PayrollRun::withCount('payslips')->orderByDesc('period')->get(),
            'payoutYear' => $payoutYear = (int) ($request->integer('year') ?: now()->year),
            'payoutRuns' => PayrollRun::withCount('payslips')->with('payslips.employee:id,name')
                ->where('period', 'like', $payoutYear.'-%')->orderByDesc('period')->get(),
            'activeRun' => $activeRun,
            // Spec F13: when payslip acknowledgement is on, who has not pressed it yet,
            // keyed by run — the payout tab lists them under each published run.
            'payslipAckOn' => $ackOn = app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'payroll.payslip_acknowledgement'),
            'payslipAckOutstanding' => $ackOn
                ? Payslip::with('employee:id,name')->whereNull('acknowledged_at')
                    ->whereHas('payrollRun', fn ($q) => $q->whereNotNull('published_at'))
                    ->get()->groupBy('payroll_run_id')
                    ->map(fn ($slips) => $slips->map(fn ($p) => $p->employee?->name)->filter()->values()->all())
                    ->all()
                : [],
            'salaryEmployees' => Employee::active()->with('salaryStructure')->orderBy('name')->get(),
            // Spec F10: who a final pay run can be created for — a recorded last working
            // day, a salary structure, and not already paid out.
            'finalPayCandidates' => $finalPayCandidates = Employee::whereNotNull('last_working_day')->whereNull('final_pay_run_id')
                ->whereHas('salaryStructure')->orderBy('name')->get(),
            // Process Payroll wizard (spec 2026-09-24): only the process screen needs it.
            'payrollWizard' => $request->route('screen') === 'payroll-process'
                ? $this->payrollWizardData($readinessRows, $finalPayCandidates, $readiness, $readinessTenant?->name)
                : null,
            // Step 4 Results: the run createRun just made, already loaded as $activeRun.
            'wizardResultRun' => $request->query('step') === 'results' && $request->filled('run') ? $activeRun : null,
            // Spec F12: statutory filings, soonest deadline first, submitted ones last.
            'payrollSubmissions' => PayrollSubmission::with('payrollRun')->orderByRaw('submitted_at is not null')->orderBy('due_on')->get(),
            // Spec F11: statutory notices, open ones first.
            'payrollNotices' => PayrollNotice::with('employee')->orderByRaw('filed_on is not null')->orderBy('due_on')->get(),
            // Spec F8: Form TP1 declarations for the year, newest first.
            'tp1Year' => $tp1Year = (int) ($request->integer('tp1_year') ?: now()->year),
            'tp1Claims' => PayrollTp1Claim::with('employee')->where('year', $tp1Year)->orderByDesc('month')->orderByDesc('id')->get(),
            'openingEmployees' => Employee::active()->orderBy('name')->get(),
            'openingFigures' => PayrollOpeningFigure::get()->groupBy('employee_id'),
            'payrollItems' => PayrollItem::orderBy('sort_order')->get(),
            // Fixed Transactions: every non-ended (or ended-in-the-future) one, grouped by
            // employee, for the Salary structures tab. currentPeriod is the default
            // start/end value the "add"/"end" forms pre-fill.
            'currentPeriod' => now()->format('Y-m'),
            'fixedTransactions' => FixedTransaction::with('payrollItem')
                ->where(fn ($q) => $q->whereNull('end_period')->orWhere('end_period', '>=', now()->format('Y-m')))
                ->orderBy('start_period')->get()->groupBy('employee_id'),
            // A Fixed Transaction must never target an item with its own automatic
            // source — see PayrollController::FT_FORBIDDEN_ITEM_CODES.
            'fixedTransactionItems' => PayrollItem::where('active', true)
                ->whereNotIn('code', ['basic-salary', 'overtime', 'unpaid-leave-deduction', 'claim-reimbursement'])
                ->orderBy('sort_order')->get(),
            // Individual Transactions queued for the currently displayed run's own period,
            // grouped by employee — the payslip edit form's tx_item_id/tx_amount/tx_remark
            // rows are pre-filled from THIS (the live table), never from the payslip's own
            // last-generated lines, so a one-off added via the standalone Individual
            // Transactions tab below is never silently overwritten by a later "Recalculate
            // & save" on the payslip edit form (see PayrollController::syncIndividualTransactions).
            'individualTransactionsForActiveRun' => $activeRun
                ? IndividualTransaction::with('payrollItem')->forPeriod($activeRun->period)->get()->groupBy('employee_id')
                : collect(),
            ...$this->individualTransactionTabData($request),
        ];
    }

    /**
     * Everything the Process Payroll wizard filters and shows, as plain arrays for Alpine.
     * The people are the readiness rows already built for this request (same "currently
     * employed" allowlist createRun uses) narrowed to those createRun would actually pay:
     * a salary structure and no final pay yet. Ids are resolved to names for the filters.
     *
     * @param  list<array{employee: Employee, blocking: list<string>, warnings: list<string>}>  $readinessRows
     * @param  EloquentCollection<int, Employee>  $finalPayCandidates
     * @return array{company: ?string, people: list<array<string, mixed>>, outside: list<array{name: string, blocking: list<string>}>, bonusByPeriod: array<string, list<int>>, leavers: list<array<string, mixed>>}
     */
    private function payrollWizardData(array $readinessRows, EloquentCollection $finalPayCandidates, PayrollReadiness $readiness, ?string $company): array
    {
        $payable = fn (array $r) => $r['employee']->salaryStructure !== null && $r['employee']->final_pay_run_id === null;
        $rows = array_values(array_filter($readinessRows, $payable));
        $relations = ['department:id,name', 'employmentType:id,name', 'positionBand:id,title', 'reportsTo:id,name', 'workSite:id,name', 'branch:id,name'];
        (new EloquentCollection(array_column($rows, 'employee')))->load($relations);
        $finalPayCandidates->load([...$relations, 'salaryStructure']);

        $person = fn (Employee $e, array $gaps): array => [
            'id' => $e->id,
            'name' => $e->name,
            // Same rule as OrgController: only an app-relative path is a usable photo.
            'photo' => $e->photo && str_starts_with($e->photo, '/') ? $e->photo : null,
            'position' => $e->position,
            'staff_id' => $e->staff_id,
            'department' => $e->department?->name,
            'gender' => $e->gender,
            'marital_status' => $e->marital_status,
            'joined_at' => $e->joined_at?->toDateString(),
            'date_of_birth' => $e->date_of_birth?->toDateString(),
            'confirmed_at' => $e->confirmed_at?->toDateString(),
            'resigned_at' => $e->resigned_at?->toDateString(),
            'salary' => (float) $e->salary,
            'pay_mode' => $e->pay_mode,
            'payment_method' => $e->payment_method,
            'employment_type' => $e->employmentType?->name,
            'status' => $e->status,
            'job_grade' => $e->job_grade,
            'direct_report' => $e->reportsTo?->name,
            'location' => $e->workSite?->name,
            'branch' => $e->branch?->name,
            'category' => $e->category,
            'division' => $e->division,
            'nationality' => $e->nationality,
            'race' => $e->race,
            'religion' => $e->religion,
            'line' => $e->line,
            'section' => $e->section,
            'blocking' => $gaps['blocking'],
            'warnings' => $gaps['warnings'],
        ];

        return [
            'company' => $company,
            'people' => array_map(fn (array $r) => $person($r['employee'], $r), $rows),
            // Currently employed but not payable (no salary structure): they never appear in
            // the selection, but their gaps can still trip the server's readiness gate.
            'outside' => array_values(array_map(
                fn (array $r) => ['name' => $r['employee']->name, 'blocking' => $r['blocking']],
                array_filter($readinessRows, fn (array $r) => ! $payable($r) && $r['blocking'] !== [] && $r['employee']->final_pay_run_id === null),
            )),
            // Bonus cycle: who has a bonus queued, per period (same rows createRun pays).
            'bonusByPeriod' => IndividualTransaction::forBonusRun(true)->select('period', 'employee_id')->distinct()->get()
                ->groupBy('period')->map(fn ($g) => $g->pluck('employee_id')->unique()->values()->all())->all(),
            'leavers' => $finalPayCandidates->map(fn (Employee $e): array => $person($e, $readiness->gapsFor($e))
                + ['last_working_day' => $e->last_working_day?->toDateString()])->values()->all(),
        ];
    }

    /**
     * Data for the standalone "Individual Transactions" tab: pick a month (itx_period
     * query param, defaults to the current month), list every one-off queued for it, and
     * flag whether that period is still editable. A period with no run yet, or a draft
     * run, is freely editable; a finalized run locks it (see
     * PayrollController::assertPeriodEditable).
     *
     * The Pay Cycle picker (itx_cycle: month_end, mid_month or bonus, as in Worksy)
     * narrows the list to the one-offs paid in that cycle's run, and itxCycleLocked says
     * whether that run is already finalized.
     *
     * @return array{itxPeriod: string, itxCycle: string, itxTransactions: Collection, itxPeriodFinalized: bool, itxBonusFinalized: bool, itxCycleLocked: bool, itxPeriodHasDraftRun: bool}
     */
    private function individualTransactionTabData(Request $request): array
    {
        $period = $request->filled('itx_period') && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('itx_period'))
            ? (string) $request->query('itx_period')
            : now()->format('Y-m');
        $cycle = in_array($request->query('itx_cycle'), ['month_end', 'mid_month', 'bonus'], true) ? (string) $request->query('itx_cycle') : 'month_end';

        // Spec F10: the monthly and the bonus run for a month lock their own rows
        // separately — a finalized monthly run must not stop HR queuing a bonus.
        $itxRun = PayrollRun::where('period', $period)->where('kind', 'monthly')->first();
        $finalizedKinds = PayrollRun::where('period', $period)->where('status', 'finalized')->pluck('kind');
        $bonusFinalized = $finalizedKinds->contains('bonus');

        return [
            'itxPeriod' => $period,
            'itxCycle' => $cycle,
            'itxTransactions' => IndividualTransaction::with(['employee', 'payrollItem'])
                ->forPeriod($period)
                ->forBonusRun($cycle === 'bonus')
                ->when($cycle !== 'bonus', fn ($q) => $q->where('payroll_cycle', $cycle))
                ->orderBy('id')->get()->groupBy('employee_id'),
            'itxPeriodFinalized' => $itxRun?->status === 'finalized',
            'itxBonusFinalized' => $bonusFinalized,
            // Same rule as PayrollController::assertPeriodEditable().
            'itxCycleLocked' => match ($cycle) {
                'bonus' => $bonusFinalized,
                'mid_month' => $finalizedKinds->contains('monthly') || $finalizedKinds->contains('mid_month'),
                default => $finalizedKinds->contains('monthly'),
            },
            // Surfaced so the UI can tell HR to recalculate the affected payslips — adding,
            // editing or deleting a one-off here does not itself touch an existing draft
            // payslip (see syncIndividualTransactions's doc comment); it becomes visible the
            // next time that payslip is recomputed.
            'itxPeriodHasDraftRun' => $itxRun !== null && $itxRun->status !== 'finalized',
        ];
    }
}
