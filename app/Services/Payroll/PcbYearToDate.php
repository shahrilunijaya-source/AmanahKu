<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\Payslip;
use App\Models\PayslipLine;

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
    /**
     * Spec F8: how much of a capped Payroll Item's yearly exemption the employee has
     * already used before $period — pay through that item on finalized runs earlier in
     * the same year, plus the take-on row's exempt allowances (the travel allowance is
     * the only capped seed item, and that is what a TP3 section C2 figure represents).
     */
    public function exemptUsed(Employee $employee, string $period, PayrollItem $item): float
    {
        [$year] = explode('-', $period);

        $used = (float) PayslipLine::where('tenant_id', $employee->tenant_id)
            ->where('payroll_item_id', $item->id)
            ->whereHas('payslip', fn ($q) => $q->where('employee_id', $employee->id)
                ->whereHas('payrollRun', fn ($r) => $r->where('status', 'finalized')
                    ->where('period', '>=', $year.'-01')
                    ->where('period', '<', $period)))
            ->sum('amount');

        $opening = PayrollOpeningFigure::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('year', (int) $year)
            ->first();

        return round($used + (float) (($opening !== null ? $opening->exempt_allowances : null) ?? 0), 2);
    }

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
            // Spec F8: pay exempted by a Payroll Item's yearly cap never entered the
            // taxable base in its own month, so it must not enter ∑Y either.
            'grossY' => (float) (($opening !== null ? $opening->gross : null) ?? 0) + (float) (($opening !== null ? $opening->additional_gross : null) ?? 0) + (float) $paidThisYear->sum('gross') - (float) $paidThisYear->sum('pcb_exempt_amount'),
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
