<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RoutesApprovalsByReportingLine;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OvertimeController extends Controller
{
    use RoutesApprovalsByReportingLine;

    /**
     * Build the overtime screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee sees their own overtime requests. The two-step gate produces two
     * queues: requests the viewer can verify (their direct reports' submitted requests)
     * and requests they can approve (every verified request, for management only).
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $myOvertime = $employee
            ? OvertimeRequest::where('employee_id', $employee->id)->latest()->get()
            : new Collection;

        $toVerify = $this->scopeToVerify(
            OvertimeRequest::with('employee')->latest(),
            $request,
        )->get();

        $toApprove = $this->scopeToApprove(
            OvertimeRequest::with(['employee', 'verifiedBy'])->latest(),
            $request,
        )->get();

        return [
            'myOvertime' => $myOvertime,
            'overtimeToVerify' => $toVerify,
            'overtimeToApprove' => $toApprove,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'ot_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'rate_multiplier' => ['required', 'in:1.50,2.00'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'ot_date' => $data['ot_date'],
            'hours' => $data['hours'],
            'rate_multiplier' => $data['rate_multiplier'],
            'reason' => $data['reason'],
            'status' => 'submitted',
        ]);

        // Step 1 of the gate: routed to the immediate superior (org chart) to verify.
        $this->notifyManagerToVerify(
            $employee,
            'Overtime awaiting your verification',
            $employee->name.' · '.number_format((float) $data['hours'], 2).'h @ '.$data['rate_multiplier'].'x',
            route('app.screen', 'overtime'),
        );

        return back()->with('ok', number_format((float) $data['hours'], 2).'h overtime submitted for approval.');
    }

    /** Step 1: the immediate superior verifies, moving the request on to management. */
    public function verify(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->assertVerifier($request, $overtime->employee, $overtime->tenant_id);
        abort_unless($overtime->status === 'submitted', 422, 'Only submitted requests can be verified.');

        // Atomic compare-and-set: only one concurrent verify flips submitted→verified —
        // the loser skips the duplicate audit + notifications.
        $flipped = OvertimeRequest::whereKey($overtime->id)->where('status', 'submitted')->update([
            'status' => 'verified',
            'verified_by_id' => $request->attributes->get('employee')?->id,
            'verified_at' => now(),
        ]);
        if ($flipped === 0) {
            return back()->with('ok', 'Overtime verified for '.$overtime->employee->name.'.');
        }

        AuditLog::record('Verified overtime', $overtime->employee->name.' · '.number_format((float) $overtime->hours, 2).'h @ '.$overtime->rate_multiplier.'x');
        $this->notifyManagementToApprove(
            $overtime->tenant_id,
            'Overtime awaiting approval',
            $overtime->employee->name.' · '.number_format((float) $overtime->hours, 2).'h @ '.$overtime->rate_multiplier.'x — verified',
            route('app.screen', 'overtime'),
        );
        AppNotification::send(
            $overtime->employee->user_id,
            'Overtime verified',
            'Your overtime was verified and is awaiting management approval',
            route('app.screen', 'overtime'),
        );

        return back()->with('ok', 'Overtime verified for '.$overtime->employee->name.'. Sent to management for approval.');
    }

    /** Step 2: management gives final approval. */
    public function approve(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->assertApprover($request, $overtime->employee, $overtime->tenant_id, $overtime->verified_by_id);
        abort_unless($overtime->status === 'verified', 422, 'A request must be verified by the immediate superior before approval.');

        // Compare-and-set so two concurrent approves don't double-audit / double-notify.
        $flipped = OvertimeRequest::whereKey($overtime->id)->where('status', 'verified')->update([
            'status' => 'approved',
            'decided_by_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        if ($flipped === 0) {
            return back()->with('ok', 'Overtime approved for '.$overtime->employee->name.'.');
        }

        AuditLog::record('Approved overtime', $overtime->employee->name.' · '.number_format((float) $overtime->hours, 2).'h @ '.$overtime->rate_multiplier.'x');
        AppNotification::send(
            $overtime->employee->user_id,
            'Overtime approved',
            number_format((float) $overtime->hours, 2).'h @ '.$overtime->rate_multiplier.'x approved',
            route('app.screen', 'overtime'),
        );

        return back()->with('ok', 'Overtime approved for '.$overtime->employee->name.'.');
    }

    public function reject(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->assertCanReject($request, $overtime);

        // Compare-and-set from either pending state so a double-click doesn't double-notify.
        $flipped = OvertimeRequest::whereKey($overtime->id)->whereIn('status', ['submitted', 'verified'])->update([
            'status' => 'rejected',
            'decided_by_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        if ($flipped === 0) {
            return back()->with('ok', 'Overtime rejected for '.$overtime->employee->name.'.');
        }

        AuditLog::record('Rejected overtime', $overtime->employee->name);
        AppNotification::send(
            $overtime->employee->user_id,
            'Overtime declined',
            'Overtime request was declined',
            route('app.screen', 'overtime'),
        );

        return back()->with('ok', 'Overtime rejected for '.$overtime->employee->name.'.');
    }
}
