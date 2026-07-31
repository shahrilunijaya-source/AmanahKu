<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobRequisition;
use App\Models\Referral;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const STATUSES = ['submitted', 'reviewing', 'interviewing', 'hired', 'rejected'];

    private const BONUS_STATUSES = ['none', 'pending', 'paid'];

    /**
     * Build the referrals screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee refers external candidates and sees only their own referrals.
     * Privileged roles (management/hr) additionally receive ALL referrals across
     * employees plus the status/bonus controls so they can move the pipeline.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        // Open roles for the "refer to a role" dropdown (recruitment is read-only here).
        $openRoles = JobRequisition::where('status', 'open')->orderBy('title')->get();

        $myReferrals = $employee
            ? Referral::with('jobRequisition')->where('referrer_employee_id', $employee->id)->latest()->get()
            : new Collection;

        $allReferrals = $privileged
            ? Referral::with(['referrer', 'jobRequisition'])->latest()->get()
            : new Collection;

        return [
            'privileged' => $privileged,
            'openRoles' => $openRoles,
            'myReferrals' => $myReferrals,
            'allReferrals' => $allReferrals,
            'statuses' => self::STATUSES,
            'bonusStatuses' => self::BONUS_STATUSES,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'candidate_name' => ['required', 'string', 'max:120'],
            'candidate_email' => ['required', 'email', 'max:160'],
            'candidate_phone' => ['nullable', 'string', 'max:40'],
            'job_requisition_id' => ['nullable', Rule::exists('job_requisitions', 'id')->where('tenant_id', $tenantId)],
            'resume_url' => ['nullable', 'url', 'max:300'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        Referral::create([
            'referrer_employee_id' => $employee->id,
            'job_requisition_id' => $data['job_requisition_id'] ?? null,
            'candidate_name' => $data['candidate_name'],
            'candidate_email' => $data['candidate_email'],
            'candidate_phone' => $data['candidate_phone'] ?? null,
            'resume_url' => $data['resume_url'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'submitted',
            'bonus_eligible' => false,
            'bonus_status' => 'none',
        ]);

        AuditLog::record('Submitted referral', $data['candidate_name']);

        return back()->with('ok', 'Referral submitted for '.$data['candidate_name'].'.');
    }

    public function setStatus(Request $request, Referral $referral): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($referral->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'bonus_eligible' => ['nullable', 'boolean'],
            'bonus_status' => ['required', Rule::in(self::BONUS_STATUSES)],
        ]);

        $decided = in_array($data['status'], ['hired', 'rejected'], true);

        $referral->update([
            'status' => $data['status'],
            'bonus_eligible' => (bool) ($data['bonus_eligible'] ?? false),
            'bonus_status' => $data['bonus_status'],
            // Stamp the decision once and keep it — moving a referral back from a
            // terminal status must not erase who decided it or when (audit trail).
            'decided_at' => $decided ? ($referral->decided_at ?? now()) : $referral->decided_at,
            'decided_by_id' => $decided
                ? ($referral->decided_by_id ?? $request->attributes->get('employee')?->id)
                : $referral->decided_by_id,
        ]);

        AuditLog::record('Updated referral', $referral->candidate_name.' → '.$data['status']);
        AppNotification::send(
            $referral->referrer?->user_id,
            'Referral updated',
            $referral->candidate_name.' · '.ucfirst($data['status']),
            route('app.screen', 'referrals'),
        );

        return back()->with('ok', 'Referral for '.$referral->candidate_name.' updated.');
    }
}
