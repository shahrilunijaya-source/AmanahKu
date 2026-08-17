<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\Position;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Token-authed REST API v1, read-only.
 *
 * Every action runs inside the tenant activated by ApiTenant middleware, so the
 * BelongsToTenant global scope already isolates rows to the token's tenant — a
 * token for tenant A physically cannot read tenant B. Role enforcement layers on
 * top: privileged roles (management|hr) see the whole tenant, everyone else sees
 * only their own records. Responses use a flat {data, error} envelope.
 */
class ApiController extends Controller
{
    private const PRIVILEGED = ['management', 'hr'];

    /** Timesheet states whose effort is final enough to bill against. See timesheetEffort(). */
    private const COUNTED_TIMESHEET_STATUSES = ['submitted', 'approved'];

    /** GET /api/v1/employees — privileged only; the tenant's employee directory. */
    public function employees(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'employees:read')) {
            return $this->denyScope('employees:read');
        }

        if (! $this->isPrivileged($request)) {
            return $this->error('This endpoint requires a management or HR role.', 403);
        }

        $employees = Employee::active()->with(['department:id,name', 'branch:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'position' => $e->position,
                'status' => $e->status,
                'department' => $e->department?->name,
                'branch' => $e->branch?->name,
            ]);

        return $this->ok($employees);
    }

    /** GET /api/v1/leave-requests — own requests, or all for privileged roles. */
    public function leaveRequests(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'leave:read')) {
            return $this->denyScope('leave:read');
        }

        $query = LeaveRequest::with(['leaveType:id,name', 'employee:id,name']);

        if (! $this->isPrivileged($request)) {
            $employee = $this->employee($request);
            if (! $employee) {
                return $this->ok([]);
            }
            $query->where('employee_id', $employee->id);
        }
        // NOTE: the privileged "all" listing intentionally still NAMES archived owners on
        // historical requests (ApiTokenTest) — history resolution deliberately skips active().

        $rows = $query->latest()->get()->map(fn (LeaveRequest $r) => [
            'id' => $r->id,
            'employee' => $r->employee?->name,
            'leave_type' => $r->leaveType?->name,
            'date_from' => $r->date_from->toDateString(),
            'date_to' => $r->date_to->toDateString(),
            'days' => $r->days,
            'status' => $r->status,
        ]);

        return $this->ok($rows);
    }

    /** GET /api/v1/payslips — own finalized payslips, or all for privileged roles. */
    public function payslips(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'payslips:read')) {
            return $this->denyScope('payslips:read');
        }

        // The API only ever exposes FINALIZED payslips — draft / in-progress runs
        // are work-in-progress (pre four-eyes approval) and must not leak through a
        // read token, regardless of role. Privileged tokens see all employees'
        // finalized payslips; non-privileged see only their own.
        $query = Payslip::with(['payrollRun:id,period,status', 'employee:id,name'])
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized'));

        if ($this->isPrivileged($request)) {
            $payslips = $query->get();
        } else {
            $employee = $this->employee($request);
            if (! $employee) {
                return $this->ok([]);
            }
            $payslips = $query->where('employee_id', $employee->id)->get();
        }

        $rows = $payslips
            ->sortByDesc(fn (Payslip $p) => $p->payrollRun?->period)
            ->values()
            ->map(fn (Payslip $p) => [
                'id' => $p->id,
                'employee' => $p->employee?->name,
                'period' => $p->payrollRun?->period,
                'run_status' => $p->payrollRun?->status,
                'gross' => $p->gross,
                'net_pay' => $p->net_pay,
                'total_deductions' => $p->total_deductions,
            ]);

        return $this->ok($rows);
    }

    /** GET /api/v1/projects — the tenant's active projects and their category tags. */
    public function projects(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'projects:read')) {
            return $this->denyScope('projects:read');
        }

        // Eager-loaded: without it the map below fires one query per project.
        $projects = Project::where('is_active', true)
            ->with('categories:id,name')
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                // Names, not ids: a category id means nothing outside AmanahKu, and a
                // consumer matching on "Development" needs no second lookup.
                'categories' => $p->categories->pluck('name')->values()->all(),
            ]);

        return $this->ok($projects);
    }

    /**
     * GET /api/v1/positions — privileged only; the tenant's position bands.
     *
     * Feeds Track's position-mapping screen, which pairs each AmanahKu band with the
     * Track position it costs against. Salary is deliberately absent: Track derives
     * its own daily rate, so no pay figure crosses the wire.
     */
    public function positions(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'positions:read')) {
            return $this->denyScope('positions:read');
        }

        if (! $this->isPrivileged($request)) {
            return $this->error('This endpoint requires a management or HR role.', 403);
        }

        // Inactive bands are included: a mapping must still cover historical effort
        // booked under a band that has since been retired.
        $positions = Position::with(['department:id,name', 'staffLevel:id,name'])
            ->orderBy('sort')
            ->orderBy('title')
            ->get()
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'code' => $p->code,
                'department' => $p->department?->name,
                'level' => $p->staffLevel?->name,
                'status' => $p->status,
            ]);

        return $this->ok($positions);
    }

    /**
     * GET /api/v1/timesheet-effort?week_start=YYYY-MM-DD — privileged only.
     *
     * Every project's effort for one week, aggregated per position band, so Track can
     * replace its hand-typed staff dedication rows. One call covers all projects: a
     * nightly run over 24 projects and 4 weeks costs 4 requests, not 96.
     *
     * A week counts once its owner has finished with it: 'submitted', or 'approved'
     * for the decided figures WeekReconciler refuses to mutate. Nothing in the app
     * sets 'approved' today, but seeded and historical rows carry it, and it is the
     * more final of the two — filtering on 'submitted' alone would silently withhold
     * real effort. A draft is still being edited and a rejected week was thrown out.
     *
     * Entries with no project (non-project categories, and the generated leave/holiday
     * rows) carry no project_id and drop out on their own.
     *
     * Aggregation is server side on purpose: no employee name, no employee id and no
     * salary figure leaves AmanahKu.
     */
    public function timesheetEffort(Request $request): JsonResponse
    {
        if (! $this->tokenCan($request, 'effort:read')) {
            return $this->denyScope('effort:read');
        }

        if (! $this->isPrivileged($request)) {
            return $this->error('This endpoint requires a management or HR role.', 403);
        }

        $weekStart = $request->validate(['week_start' => ['required', 'date']])['week_start'];

        // The counted weeks are selected on their own builder rather than inside a
        // whereHas() closure: a closure argument is typed Builder<Model>, which cannot
        // see Timesheet's forWeek() scope. Same rows, one indexed subquery on the FK.
        $countedWeeks = Timesheet::query()
            ->whereIn('status', self::COUNTED_TIMESHEET_STATUSES)
            ->forWeek($weekStart)
            ->select('id');

        $entries = TimesheetEntry::query()
            ->whereNotNull('project_id')
            ->whereIn('timesheet_id', $countedWeeks)
            ->with('timesheet.employee')
            ->get();

        $projects = $entries
            ->groupBy('project_id')
            ->map(fn (Collection $rows, int|string $projectId) => [
                'project_id' => (int) $projectId,
                'positions' => $this->effortByPosition($rows),
            ])
            ->sortBy('project_id')
            ->values();

        return $this->ok([
            'week_start' => CarbonImmutable::parse($weekStart)->toDateString(),
            'projects' => $projects,
        ]);
    }

    /**
     * Collapse one project's entries into one row per position band.
     *
     * `alloc_pct` is the average dedication across the people in the band, so the row
     * reads the way a PM would have typed it: headcount, average allocation, days. It
     * cannot exceed 100 because a single employee-day never exceeds 100 percent.
     *
     * @param  Collection<int, TimesheetEntry>  $rows
     * @return array<int, array{position_id: ?int, position_title: ?string, headcount: int, person_days: float, days_present: int, alloc_pct: float}>
     */
    private function effortByPosition(Collection $rows): array
    {
        return $rows
            // Employees with no position band share one bucket; Track flags it the same
            // way it flags a band nobody has mapped yet.
            ->groupBy(fn (TimesheetEntry $entry) => $entry->timesheet->employee->position_id ?? 'unbanded')
            ->map(function (Collection $banded) {
                $band = $banded->first()->timesheet->employee->positionBand;
                $personDays = round($banded->sum(fn (TimesheetEntry $e) => (float) $e->percentage) / 100, 2);
                $headcount = $banded->pluck('timesheet.employee_id')->unique()->count();
                $daysPresent = $banded->pluck('entry_date')->map->toDateString()->unique()->count();
                $slots = $headcount * $daysPresent;

                return [
                    'position_id' => $band?->id,
                    'position_title' => $band?->title,
                    'headcount' => $headcount,
                    'person_days' => $personDays,
                    'days_present' => $daysPresent,
                    'alloc_pct' => $slots > 0 ? round($personDays / $slots * 100, 2) : 0.0,
                ];
            })
            ->sortBy(fn (array $row) => $row['position_title'] ?? '')
            ->values()
            ->all();
    }

    /**
     * Whether the caller's token carries a scope. A person-token is minted ['*'],
     * which Sanctum treats as every ability, so this is true for all of them and the
     * guards below are invisible to the existing token stack.
     */
    private function tokenCan(Request $request, string $scope): bool
    {
        /** @var list<string> $abilities */
        $abilities = $request->attributes->get('tokenAbilities', []);

        return in_array('*', $abilities, true) || in_array($scope, $abilities, true);
    }

    private function denyScope(string $scope): JsonResponse
    {
        return $this->error("This token lacks the {$scope} scope.", 403);
    }

    /**
     * Whether the caller may see the whole tenant rather than only its own records.
     *
     * A machine caller always may: it cleared its scope guard to get here, and the
     * super-admin who ticked that scope was the authorization act. There is no "own
     * records" for an app — it has no employee record to own any. A person-token is
     * judged exactly as before, on its tenant role.
     */
    private function isPrivileged(Request $request): bool
    {
        return $request->attributes->get('apiClient') !== null
            || in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED, true);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->attributes->get('employee');
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['data' => $data, 'error' => null]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['data' => null, 'error' => $message], $status);
    }
}
