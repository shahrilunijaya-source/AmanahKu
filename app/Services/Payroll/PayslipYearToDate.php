<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollOpeningFigure;
use App\Models\Payslip;

/**
 * Statutory year-to-date figures for the payslip PDF's STATUTORY SUMMARY table: EPF,
 * SOCSO, EIS, PCB and SKBBK, split employee/employer, each as {month, ytd}.
 *
 * Unlike PcbYearToDate (which deliberately ignores PayrollOpeningFigure's socso/eis —
 * that restriction is about the LHDN tax formula only), this service DOES fold opening
 * socso/eis into the employee-side YTD, because that is exactly why those two columns
 * were added to the opening-figures table (record-keeping / EA-form display).
 *
 * Only finalized runs count, and only runs earlier in the same calendar year as the
 * payslip being viewed — a draft/approved run can still change, so it must never leak
 * into another month's YTD.
 *
 * Employer-side and SKBBK openings come from the take-on row's `ea_lines`
 * (employer_epf, employer_socso, employer_eis, skbbk); a row without them starts at 0.
 */
final class PayslipYearToDate
{
    /**
     * @return array<string, array{employee: array{month: float, ytd: float}, employer?: array{month: float, ytd: float}}>
     */
    public function forPayslip(Payslip $payslip): array
    {
        $employee = $payslip->employee;
        $run = $payslip->payrollRun;
        [$year] = explode('-', $run->period);

        $opening = PayrollOpeningFigure::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('year', (int) $year)
            ->first();

        $priorPaid = Payslip::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')
                ->where('period', '>=', $year.'-01')
                ->where('period', '<', $run->period))
            ->get();

        $row = fn (float $month, float $opening, float $priorSum) => [
            'month' => round($month, 2),
            'ytd' => round($opening + $priorSum + $month, 2),
        ];

        // Larastan false-positives "nullsafe.neverNull" on `$opening?->x ?? 0` below even
        // though ->first() is genuinely nullable — an employee with no take-on row is the
        // common case, not an edge case, so this is NOT dead code. Written as an explicit
        // null check instead of ?-> to sidestep the false positive rather than silence it.
        $openingEpf = ($opening !== null ? $opening->epf : null) ?? 0;
        $openingAdditionalEpf = ($opening !== null ? $opening->additional_epf : null) ?? 0;
        $openingSocso = ($opening !== null ? $opening->socso : null) ?? 0;
        $openingEis = ($opening !== null ? $opening->eis : null) ?? 0;
        $openingPcbPaid = ($opening !== null ? $opening->pcb_paid : null) ?? 0;
        $line = fn (string $box) => $opening !== null ? $opening->line($box) : 0.0;

        return [
            'epf' => [
                'employee' => $row(
                    (float) $payslip->epf_employee,
                    (float) $openingEpf + (float) $openingAdditionalEpf,
                    (float) $priorPaid->sum('epf_employee'),
                ),
                'employer' => $row((float) $payslip->epf_employer, $line('employer_epf'), (float) $priorPaid->sum('epf_employer')),
            ],
            'socso' => [
                'employee' => $row((float) $payslip->socso_employee, (float) $openingSocso, (float) $priorPaid->sum('socso_employee')),
                'employer' => $row((float) $payslip->socso_employer, $line('employer_socso'), (float) $priorPaid->sum('socso_employer')),
            ],
            'eis' => [
                'employee' => $row((float) $payslip->eis_employee, (float) $openingEis, (float) $priorPaid->sum('eis_employee')),
                'employer' => $row((float) $payslip->eis_employer, $line('employer_eis'), (float) $priorPaid->sum('eis_employer')),
            ],
            'pcb' => [
                'employee' => $row(
                    (float) $payslip->pcb + (float) $payslip->pcb_additional,
                    (float) $openingPcbPaid,
                    (float) $priorPaid->sum(fn (Payslip $p) => $p->pcb + $p->pcb_additional),
                ),
            ],
            'skbbk' => [
                'employee' => $row((float) $payslip->skbbk_employee, $line('skbbk'), (float) $priorPaid->sum('skbbk_employee')),
            ],
        ];
    }
}
