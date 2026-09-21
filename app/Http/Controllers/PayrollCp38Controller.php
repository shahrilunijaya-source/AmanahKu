<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollCp38Notice;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spec F9: LHDN CP38 directions. HR records the notice once; the running balance is
 * moved by Cp38Notices when a run is finalized, never from here.
 */
class PayrollCp38Controller extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'reference' => ['nullable', 'string', 'max:60'],
            'notice_date' => ['nullable', 'date'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_instalment' => ['required', 'numeric', 'min:0.01'],
            'first_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'last_period' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $notice = PayrollCp38Notice::create([
            'employee_id' => $data['employee_id'],
            'reference' => $data['reference'] ?? null,
            'notice_date' => $data['notice_date'] ?? null,
            'total_amount' => $data['total_amount'] ?? null,
            'monthly_instalment' => $data['monthly_instalment'],
            'first_period' => $data['first_period'],
            'last_period' => $data['last_period'] ?? null,
            // A notice with a stated total counts down from it; an open-ended one never does.
            'remaining_balance' => $data['total_amount'] ?? null,
            'status' => 'active',
            'created_by_id' => $request->user()?->id,
        ]);

        $name = Employee::find($data['employee_id'])?->name;
        AuditLog::record('Added CP38 notice', $name.' · RM '.number_format((float) $notice->monthly_instalment, 2).'/month from '.$notice->first_period);

        return back()->with('ok', 'CP38 notice added.');
    }

    public function cancel(Request $request, PayrollCp38Notice $notice): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        // Route-model binding resolves across every tenant — check ownership explicitly.
        abort_unless($notice->tenant_id === app(CurrentTenant::class)->id(), 403);

        $notice->forceFill(['status' => 'cancelled'])->save();
        AuditLog::record('Cancelled CP38 notice', ($notice->employee !== null ? $notice->employee->name : null).' · notice #'.$notice->id);

        return back()->with('ok', 'CP38 notice cancelled.');
    }
}
