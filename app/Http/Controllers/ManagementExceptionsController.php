<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OverdueLedger;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Support\AuditContext;
use App\Support\DashboardBands;
use App\Support\ManagementExceptions;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CR-17: the same lateness/overdue figures shown on the dashboard `management` band,
 * scoped to the viewer, plus the two card actions (nudge, reassign) and the dedicated
 * page for a branch/company-scope manager who does not get the band (dashboard-slots.md
 * keeps that FINAL_APPROVAL_ROLES-only; CR32Test pins it).
 */
class ManagementExceptionsController extends Controller
{
    public function __construct(private ManagementExceptions $exceptions, private DataScope $dataScope) {}

    /**
     * Data for GET /app/management/exceptions. Aborts 403 for anyone the screen is not
     * for: a team-scoped manager, plain staff, or a branch-scoped manager whose data
     * scope is narrower than branch. FINAL_APPROVAL_ROLES read company-wide by default,
     * narrowable to their own line with `?scope=staff`.
     *
     * @return array{mgmt: array<string, mixed>}
     */
    public function pageData(Request $request): array
    {
        $rawRole = $this->tenantRole($request);
        $role = Permissions::effectiveRole($rawRole ?? 'employee');
        $employee = $request->attributes->get('employee');
        $scopeQuery = $request->query('scope');

        if (in_array($role, Permissions::FINAL_APPROVAL_ROLES, true)) {
            $employeeIds = ($scopeQuery === 'staff' && $employee) ? $this->dataScope->teamIds($employee) : null;
        } elseif ($rawRole === 'manager') {
            $tenantScope = $request->attributes->get('tenantScope');
            abort_unless(in_array($tenantScope, ['branch', 'company'], true), 403);
            abort_unless($employee !== null, 403);
            $employeeIds = $this->dataScope->teamIds($employee);
        } else {
            abort(403);
        }

        $overdue = $this->exceptions->overdue($employeeIds);
        if ($employee) {
            $overdue = $this->exceptions->withReassignFlags($overdue, $employee, $role);
        }

        $mgmt = DashboardBands::managementSlot(
            $this->exceptions->lateness($employeeIds),
            $overdue,
            $employeeIds === null ? 'company' : 'staff',
        );

        return ['mgmt' => $mgmt];
    }

    /** POST /app/management/overdue/{card}/nudge — a reminder notice, at most once a day. */
    public function nudge(Request $request, WorkItem $card): JsonResponse
    {
        abort_unless($card->tenant_id === app(CurrentTenant::class)->id(), 404);

        $actor = $this->employee($request);
        abort_unless($this->canActOnOwner($request, $actor, $card), 403);
        abort_if(in_array($card->status, ['done'], true) || $card->cancelled_at !== null, 422, 'This card is no longer open.');

        $alreadyToday = AuditLog::where('tenant_id', $card->tenant_id)
            ->where('subject_type', WorkItem::class)->where('subject_id', $card->id)
            ->where('action', 'like', '%nudge%')
            ->whereDate('created_at', Carbon::now()->toDateString())
            ->exists();
        abort_if($alreadyToday, 422, 'This card was already nudged today.');

        AuditLog::change($card, 'nudged', null, Carbon::now()->toDateString());

        if ($card->employee?->user_id) {
            AppNotification::send($card->employee->user_id, $actor->display_name.' nudged you about: '.$card->title, null, route('app.screen', 'board').'?card='.$card->id);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /app/management/overdue/{card}/reassign — moves the card, never the due date. */
    public function reassign(Request $request, WorkItem $card): JsonResponse
    {
        abort_unless($card->tenant_id === app(CurrentTenant::class)->id(), 404);

        $actor = $this->employee($request);
        abort_unless($this->canReassign($request, $actor, $card), 403);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $card->tenant_id)->whereNull('archived_at')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $to = Employee::findOrFail($data['employee_id']);
        if ($to->id === $card->employee_id) {
            throw ValidationException::withMessages(['employee_id' => 'That person already owns this card.']);
        }

        $from = $card->employee;
        $dueAt = $card->due_at;

        AuditContext::reason($data['reason']);
        try {
            $card->update(['employee_id' => $to->id]);
        } finally {
            AuditContext::reason(null);
        }

        if ($from && $dueAt) {
            OverdueLedger::create([
                'work_item_id' => $card->id,
                'employee_id' => $from->id,
                'month' => $dueAt->copy()->startOfMonth()->toDateString(),
                'days_overdue' => (int) Carbon::now()->startOfDay()->diffInDays($dueAt->copy()->startOfDay(), true),
            ]);
        }

        foreach (array_filter([$from, $to]) as $person) {
            if ($person->user_id) {
                AppNotification::send($person->user_id, 'Card reassigned: '.$card->title, $data['reason'], route('app.screen', 'board').'?card='.$card->id);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        return $employee;
    }

    /** FINAL_APPROVAL_ROLES always may; a manager may only over the card owner's reporting line. */
    private function canActOnOwner(Request $request, Employee $actor, WorkItem $card): bool
    {
        $role = Permissions::effectiveRole($this->tenantRole($request) ?? 'employee');
        if (in_array($role, Permissions::FINAL_APPROVAL_ROLES, true)) {
            return true;
        }

        $tenantScope = $request->attributes->get('tenantScope');
        if ($this->tenantRole($request) === 'manager' && in_array($tenantScope, ['branch', 'company'], true) && $card->employee_id) {
            return in_array($card->employee_id, $this->dataScope->teamIds($actor), true);
        }

        return false;
    }

    private function canReassign(Request $request, Employee $actor, WorkItem $card): bool
    {
        $role = Permissions::effectiveRole($this->tenantRole($request) ?? 'employee');

        return $this->exceptions->canReassign($actor, $role, $card->employee?->reports_to_id, $card->projectRef?->pm_id);
    }
}
