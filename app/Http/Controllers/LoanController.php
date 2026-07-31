<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSingleStepApproval;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LoanRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LoanController extends Controller
{
    use AuthorizesSingleStepApproval;

    /**
     * Build the loans screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee sees their own loan/advance requests and balances. Privileged
     * roles additionally receive the pending-approvals queue (with the requesting
     * employee eager-loaded) so they can approve or reject.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, $this->singleStepApproverRoles());

        $myLoans = $employee
            ? LoanRequest::where('employee_id', $employee->id)->latest()->get()
            : new Collection;

        $pendingLoans = $privileged
            ? LoanRequest::with('employee')->where('status', 'submitted')->latest()->get()
            : new Collection;

        return [
            'myLoans' => $myLoans,
            'pendingLoans' => $pendingLoans,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'type' => ['required', 'in:loan,advance'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'reason' => ['required', 'string', 'max:500'],
            'installments' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        LoanRequest::create([
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'installments' => $data['installments'],
            'status' => 'submitted',
        ]);

        // Notify the requester's immediate superior that a request awaits review (AK-PROC-02).
        if ($employee->reportsTo?->user_id) {
            AppNotification::send(
                $employee->reportsTo->user_id,
                ucfirst($data['type']).' request awaiting approval',
                $employee->name.' · RM '.number_format((float) $data['amount'], 2),
                route('app.screen', 'loans'),
            );
        }

        return back()->with('ok', 'Request submitted for RM '.number_format((float) $data['amount'], 2).'.');
    }

    public function approve(Request $request, LoanRequest $loan): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $loan, $loan->employee_id);
        abort_unless($loan->status === 'submitted', 422);

        $loan->update([
            'status' => 'approved',
            'approved_by_employee_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        AuditLog::record('Approved '.$loan->type, $loan->employee->name.' · RM '.number_format($loan->amount, 2));
        AppNotification::send(
            $loan->employee->user_id,
            ucfirst($loan->type).' approved',
            ucfirst($loan->type).' · RM '.number_format($loan->amount, 2),
            route('app.screen', 'loans'),
        );

        return back()->with('ok', ucfirst($loan->type).' approved for '.$loan->employee->name.'.');
    }

    public function reject(Request $request, LoanRequest $loan): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $loan, $loan->employee_id);
        abort_unless($loan->status === 'submitted', 422);

        $loan->update([
            'status' => 'rejected',
            'approved_by_employee_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        AuditLog::record('Rejected '.$loan->type, $loan->employee->name);
        AppNotification::send(
            $loan->employee->user_id,
            ucfirst($loan->type).' declined',
            ucfirst($loan->type).' request was declined',
            route('app.screen', 'loans'),
        );

        return back()->with('ok', ucfirst($loan->type).' rejected for '.$loan->employee->name.'.');
    }
}
