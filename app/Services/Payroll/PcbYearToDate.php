<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollTp1Claim;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Support\Tp1Reliefs;

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

    /** @return array{grossY: float, epfK: float, zakatZ: float, mtdPaidX: float, optionalDeductions: float, currentOptionalDeductions: float, currentZakat: float} */
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

        $tp1 = $this->tp1Totals($employee, $period);

        // Larastan false-positives "nullsafe.neverNull" on `$opening?->x ?? 0` here even
        // though ->first() is genuinely nullable — an employee with no take-on row is the
        // common case, not an edge case, so this is NOT dead code. Written as an explicit
        // null check instead of ?-> to sidestep the false positive rather than silence it.
        return [
            // Spec F8: pay exempted by a Payroll Item's yearly cap never entered the
            // taxable base in its own month, so it must not enter ∑Y either.
            // Take-on lines B1(c) to B1(f) are taxable pay too. ponytail: B2 to B6 (arrears,
            // benefits in kind, accommodation, refunds, compensation) stay out of ∑Y, the way
            // our own pay runs never produce them; add them if a client needs PCB on them.
            'grossY' => (float) (($opening !== null ? $opening->gross : null) ?? 0) + (float) (($opening !== null ? $opening->additional_gross : null) ?? 0)
                + ($opening !== null ? $opening->line('b1c') + $opening->line('b1d') + $opening->line('b1e') + $opening->line('b1f') : 0.0) + (float) $paidThisYear->sum('gross') - (float) $paidThisYear->sum('pcb_exempt_amount'),
            'epfK' => (float) (($opening !== null ? $opening->epf : null) ?? 0) + (float) (($opening !== null ? $opening->additional_epf : null) ?? 0) + (float) $paidThisYear->sum('epf_employee'),
            // TP1 zakat never reaches the payslip (LHDN spec p.35), so earlier months'
            // TP1 zakat has to be added to Z alongside what was deducted from pay.
            // Take-on box D5(b) is TP1 zakat paid outside salary, which Z counts the same way.
            'zakatZ' => (float) (($opening !== null ? $opening->zakat_paid : null) ?? 0) + ($opening !== null ? $opening->line('d5b') : 0.0) + (float) $paidThisYear->sum('zakat') + $tp1['ytdZakat'],
            'mtdPaidX' => (float) (($opening !== null ? $opening->pcb_paid : null) ?? 0) + (float) $paidThisYear->sum(fn ($p) => $p->pcb + $p->pcb_additional),
            // ∑LP opening balance — TP1 optional deductions (parents' medical, study fees,
            // etc.) the employee already claimed through a previous employer this year.
            // Nothing on Payslip accumulates a current-year TP1 figure yet, so this is the
            // opening balance only; wire in this app's own TP1 claims here once they exist.
            // ∑LP — TP1 optional deductions already claimed this year: the opening
            // balance from a previous employer plus this app's own earlier-month claims,
            // each trimmed to its relief's yearly cap (Tp1Reliefs).
            'optionalDeductions' => round((float) (($opening !== null ? $opening->optional_deductions : null) ?? 0) + $tp1['ytdOptional'], 2),
            'currentOptionalDeductions' => $tp1['currentOptional'],
            'currentZakat' => $tp1['currentZakat'],
        ];
    }

    /**
     * The whole year's capped TP1 figures, for C.P.8D fields 15 and 16.
     *
     * @return array{optional: float, zakat: float}
     */
    public function tp1YearTotals(Employee $employee, int $year): array
    {
        $t = $this->tp1Totals($employee, $year.'-12');

        return [
            'optional' => round($t['ytdOptional'] + $t['currentOptional'], 2),
            'zakat' => round($t['ytdZakat'] + $t['currentZakat'], 2),
        ];
    }

    /**
     * Spec F8: this employee's Form TP1 claims for the year, split into what belongs to
     * months before $period and what belongs to $period itself. Each relief's yearly cap
     * is applied in month order, so an earlier month uses the cap first.
     *
     * @return array{ytdOptional: float, ytdZakat: float, currentOptional: float, currentZakat: float}
     */
    private function tp1Totals(Employee $employee, string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        $totals = ['ytdOptional' => 0.0, 'ytdZakat' => 0.0, 'currentOptional' => 0.0, 'currentZakat' => 0.0];
        $usedByCode = [];

        $claims = PayrollTp1Claim::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->orderBy('month')->orderBy('id')->get();

        foreach ($claims as $claim) {
            if ($claim->month > $month) {
                continue;
            }
            $current = $claim->month === $month;
            $totals[$current ? 'currentZakat' : 'ytdZakat'] += (float) $claim->zakat_amount;

            $code = $claim->relief_code;
            if ($code === null || (float) $claim->amount <= 0) {
                continue;
            }
            $allowed = Tp1Reliefs::allowed($code, (float) $claim->amount, $usedByCode[$code] ?? 0.0);
            $usedByCode[$code] = ($usedByCode[$code] ?? 0.0) + $allowed;
            $totals[$current ? 'currentOptional' : 'ytdOptional'] += $allowed;
        }

        return array_map(fn (float $v) => round($v, 2), $totals);
    }
}
