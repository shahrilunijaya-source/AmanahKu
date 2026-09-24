<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollCp38Month;
use App\Models\Payslip;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CP38, Worksy style: HR saves one employee's 12-month grid for a year. A month whose
 * pay run is already finalized is left alone, since that payslip can no longer change.
 */
class PayrollCp38Controller extends Controller
{
    /** @var list<string> */
    private const ADMIN_ROLES = ['management', 'hr'];

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'amounts' => ['required', 'array', 'size:12'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        $locked = self::finalizedPeriods($employee->id);

        DB::transaction(function () use ($data, $employee, $locked) {
            foreach (array_values($data['amounts']) as $i => $value) {
                $period = sprintf('%04d-%02d', $data['year'], $i + 1);
                if (in_array($period, $locked, true)) {
                    continue;
                }
                $amount = round((float) ($value ?? 0), 2);
                $match = ['employee_id' => $employee->id, 'period' => $period];
                if ($amount > 0) {
                    PayrollCp38Month::updateOrCreate($match, ['amount' => $amount]);
                } else {
                    PayrollCp38Month::where($match)->delete();
                }
            }
        });

        AuditLog::record('Updated CP38', $employee->name.' · '.$data['year']);

        return redirect()->to(route('app.screen', ['screen' => 'payroll-transaction', 'tab' => 'cp38', 'emp' => $employee->id, 'cp38_year' => $data['year']]))
            ->with('ok', 'CP38 saved.');
    }

    /**
     * Months ('YYYY-MM') where this employee has a finalized monthly or final pay run.
     * Bonus runs never take CP38, so they do not lock a month.
     *
     * @return list<string>
     */
    public static function finalizedPeriods(int $employeeId): array
    {
        return Payslip::where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->where('kind', '<>', 'bonus'))
            ->with('payrollRun:id,period')->get()
            ->map(fn (Payslip $p) => $p->payrollRun->period)->unique()->values()->all();
    }
}
