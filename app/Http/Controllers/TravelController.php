<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSingleStepApproval;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TravelRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TravelController extends Controller
{
    use AuthorizesSingleStepApproval;

    private const TRANSPORT = ['flight', 'car', 'train', 'other'];

    /**
     * Build the travel screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee sees their own travel requests. Privileged roles additionally
     * get a pending-approvals queue (submitted requests across the tenant, with the
     * requesting employee eager-loaded) — a plain employee never receives other
     * people's requests in their template.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, $this->singleStepApproverRoles());

        return [
            'privileged' => $privileged,
            'transport' => self::TRANSPORT,
            'myRequests' => $employee
                ? TravelRequest::with('approver')
                    ->where('employee_id', $employee->id)
                    ->latest('depart_date')->latest('id')->get()
                : new Collection,
            'pendingApprovals' => $privileged
                ? TravelRequest::with('employee')
                    ->where('status', 'submitted')
                    ->latest('created_at')->get()
                : new Collection,
        ];
    }

    /** Any employee in the workspace may submit a business trip request. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'destination' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:1000'],
            'depart_date' => ['required', 'date'],
            'return_date' => ['required', 'date', 'after_or_equal:depart_date'],
            'transport' => ['required', Rule::in(self::TRANSPORT)],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        // No travelRequests() relation is defined on Employee (and that model is
        // off-limits), so bind the requester explicitly — this also means the
        // employee_id can never be spoofed. tenant_id is auto-filled by BelongsToTenant.
        TravelRequest::create([
            'employee_id' => $employee->id,
            'destination' => $data['destination'],
            'purpose' => $data['purpose'],
            'depart_date' => $data['depart_date'],
            'return_date' => $data['return_date'],
            'transport' => $data['transport'],
            'estimated_cost' => $data['estimated_cost'] ?? null,
            'status' => 'submitted',
        ]);

        AuditLog::record('Submitted travel request', $employee->name.' · '.$data['destination']);

        // Notify the requester's immediate superior that a request awaits review (AK-PROC-02).
        if ($employee->reportsTo?->user_id) {
            AppNotification::send(
                $employee->reportsTo->user_id,
                'Travel request awaiting approval',
                $employee->name.' · '.$data['destination'],
                route('app.screen', 'travel'),
            );
        }

        return back()->with('ok', 'Travel request submitted for '.$data['destination'].'.');
    }

    public function approve(Request $request, TravelRequest $travel): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $travel, $travel->employee_id);
        abort_unless($travel->status === 'submitted', 422, 'Only submitted requests can be approved.');

        $travel->update([
            'status' => 'approved',
            'approved_by_employee_id' => $request->attributes->get('employee')?->id,
            'decided_at' => Carbon::now(),
        ]);

        AuditLog::record('Approved travel', $travel->employee->name.' · '.$travel->destination);
        AppNotification::send(
            $travel->employee->user_id,
            'Travel approved',
            $travel->destination.' · '.$travel->depart_date->format('j M Y'),
            route('app.screen', 'travel'),
        );

        return back()->with('ok', 'Travel approved for '.$travel->employee->name.'.');
    }

    public function reject(Request $request, TravelRequest $travel): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $travel, $travel->employee_id);
        abort_unless($travel->status === 'submitted', 422, 'Only submitted requests can be rejected.');

        $travel->update([
            'status' => 'rejected',
            'approved_by_employee_id' => $request->attributes->get('employee')?->id,
            'decided_at' => Carbon::now(),
        ]);

        AuditLog::record('Rejected travel', $travel->employee->name.' · '.$travel->destination);
        AppNotification::send(
            $travel->employee->user_id,
            'Travel declined',
            $travel->destination.' request was declined',
            route('app.screen', 'travel'),
        );

        return back()->with('ok', 'Travel rejected for '.$travel->employee->name.'.');
    }
}
