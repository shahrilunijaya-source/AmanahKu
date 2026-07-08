<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** GET /api/v1/employees — privileged only; the tenant's employee directory. */
    public function employees(Request $request): JsonResponse
    {
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
            'date_from' => $r->date_from?->toDateString(),
            'date_to' => $r->date_to?->toDateString(),
            'days' => $r->days,
            'status' => $r->status,
        ]);

        return $this->ok($rows);
    }

    /** GET /api/v1/payslips — own finalized payslips, or all for privileged roles. */
    public function payslips(Request $request): JsonResponse
    {
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

    private function isPrivileged(Request $request): bool
    {
        return in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED, true);
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
