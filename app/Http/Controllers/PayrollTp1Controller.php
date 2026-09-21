<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollTp1Claim;
use App\Support\Tp1Reliefs;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spec F8: Form TP1 declarations. A claim feeds the month's PCB through PcbYearToDate,
 * so it may only be recorded against a month whose payroll has not been finalized yet.
 */
class PayrollTp1Controller extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'relief_code' => ['nullable', 'string', Rule::in(array_keys(Tp1Reliefs::LIST))],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'zakat_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $period = sprintf('%04d-%02d', $data['year'], $data['month']);
        $this->assertPeriodOpen($tid, $period);

        $claim = PayrollTp1Claim::create([
            'employee_id' => $data['employee_id'],
            'year' => $data['year'],
            'month' => $data['month'],
            'relief_code' => $data['relief_code'] ?? null,
            'amount' => $data['amount'] ?? 0,
            'zakat_amount' => $data['zakat_amount'] ?? 0,
            'note' => $data['note'] ?? null,
            'created_by_id' => $request->user()?->id,
        ]);

        $name = Employee::find($data['employee_id'])?->name;
        AuditLog::record('Added TP1 claim', $name.' · '.$period.' · '.($claim->relief_code ?? 'zakat').' RM '.number_format((float) $claim->amount + (float) $claim->zakat_amount, 2));

        return back()->with('ok', 'TP1 claim recorded.');
    }

    public function destroy(Request $request, PayrollTp1Claim $claim): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        // Route-model binding resolves across every tenant — check ownership explicitly.
        abort_unless($claim->tenant_id === app(CurrentTenant::class)->id(), 403);

        $period = sprintf('%04d-%02d', $claim->year, $claim->month);
        $this->assertPeriodOpen($claim->tenant_id, $period);

        $name = ($claim->employee !== null ? $claim->employee->name : null);
        $claim->delete();
        AuditLog::record('Deleted TP1 claim', $name.' · '.$period);

        return back()->with('ok', 'TP1 claim removed.');
    }

    /** A finalized month's PCB has already been issued — a TP1 claim goes in the next one. */
    private function assertPeriodOpen(int $tid, string $period): void
    {
        $finalized = PayrollRun::where('tenant_id', $tid)->where('period', $period)
            ->where('status', 'finalized')->exists();
        abort_if($finalized, 422, 'Payroll for '.$period.' is already finalized — enter it in the next month.');
    }
}
