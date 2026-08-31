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

        // Larastan false-positives "nullsafe.neverNull" on `$opening?->x ?? 0` here even
        // though ->first() is genuinely nullable — an employee with no take-on row is the
        // common case, not an edge case, so this is NOT dead code. Written as an explicit
        // null check instead of ?-> to sidestep the false positive rather than silence it.
        return [
            'grossY' => (float) (($opening !== null ? $opening->gross : null) ?? 0) + (float) (($opening !== null ? $opening->additional_gross : null) ?? 0) + (float) $paidThisYear->sum('gross'),
            'epfK' => (float) (($opening !== null ? $opening->epf : null) ?? 0) + (float) (($opening !== null ? $opening->additional_epf : null) ?? 0) + (float) $paidThisYear->sum('epf_employee'),
            'zakatZ' => (float) (($opening !== null ? $opening->zakat_paid : null) ?? 0) + (float) $paidThisYear->sum('zakat'),
            'mtdPaidX' => (float) (($opening !== null ? $opening->pcb_paid : null) ?? 0) + (float) $paidThisYear->sum(fn ($p) => $p->pcb + $p->pcb_additional),
            // ∑LP opening balance — TP1 optional deductions (parents' medical, study fees,
            // etc.) the employee already claimed through a previous employer this year.
            // Nothing on Payslip accumulates a current-year TP1 figure yet, so this is the
            // opening balance only; wire in this app's own TP1 claims here once they exist.
            'optionalDeductions' => (float) (($opening !== null ? $opening->optional_deductions : null) ?? 0),
        ];
    }
}
