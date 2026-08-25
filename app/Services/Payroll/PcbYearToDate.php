<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\Payslip;

/**
 * Year-to-date figures PcbCalculator needs (∑Y, ∑K, Z, X) for one employee at one pay
 * period: everything paid in FINALIZED runs earlier in the same calendar year, plus
 * whatever a previous employer/system already paid before this app took over
 * (PayrollOpeningFigure — see the migration + model docblocks).
 *
 * Draft/approved run payslips never count — they can still be edited, deleted, or
 * recomputed, so counting them would make one month's PCB depend on another month's
 * unfinished work.
 */
final class PcbYearToDate
{
    /** @return array{grossY: float, epfK: float, zakatZ: float, mtdPaidX: float, optionalDeductions: float} */
    public function forPeriod(Employee $employee, string $period): array
    {
        [$year] = explode('-', $period);

        $opening = PayrollOpeningFigure::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('year', (int) $year)
            ->first();

        // gross already includes bonus/additional remuneration (PayrollCalculator sums
        // it all into one figure) — the spec's ∑Y wants exactly that combined total.
        $paidThisYear = Payslip::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')
                ->where('period', '>=', $year.'-01')
                ->where('period', '<', $period))
            ->get();

        return [
            'grossY' => (float) ($opening?->gross ?? 0) + (float) ($opening?->additional_gross ?? 0) + (float) $paidThisYear->sum('gross'),
            'epfK' => (float) ($opening?->epf ?? 0) + (float) ($opening?->additional_epf ?? 0) + (float) $paidThisYear->sum('epf_employee'),
            'zakatZ' => (float) ($opening?->zakat_paid ?? 0) + (float) $paidThisYear->sum('zakat'),
            'mtdPaidX' => (float) ($opening?->pcb_paid ?? 0) + (float) $paidThisYear->sum(fn ($p) => $p->pcb + $p->pcb_additional),
            // ∑LP opening balance — TP1 optional deductions (parents' medical, study fees,
            // etc.) the employee already claimed through a previous employer this year.
            // Nothing on Payslip accumulates a current-year TP1 figure yet, so this is the
            // opening balance only; wire in this app's own TP1 claims here once they exist.
            'optionalDeductions' => (float) ($opening?->optional_deductions ?? 0),
        ];
    }
}
