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
 * ponytail: PayrollOpeningFigure has no employer-side EPF/SOCSO/EIS column (only the
 * employee side a previous system reported), so employer YTD before this app took over
 * is simply unknown and starts at 0. Add employer opening columns if a client needs an
 * exact employer YTD from day one of the calendar year.
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

        return [
            'epf' => [
                'employee' => $row(
                    (float) $payslip->epf_employee,
                    (float) ($opening?->epf ?? 0) + (float) ($opening?->additional_epf ?? 0),
                    (float) $priorPaid->sum('epf_employee'),
                ),
                'employer' => $row((float) $payslip->epf_employer, 0.0, (float) $priorPaid->sum('epf_employer')),
            ],
            'socso' => [
                'employee' => $row((float) $payslip->socso_employee, (float) ($opening?->socso ?? 0), (float) $priorPaid->sum('socso_employee')),
                'employer' => $row((float) $payslip->socso_employer, 0.0, (float) $priorPaid->sum('socso_employer')),
            ],
            'eis' => [
                'employee' => $row((float) $payslip->eis_employee, (float) ($opening?->eis ?? 0), (float) $priorPaid->sum('eis_employee')),
                'employer' => $row((float) $payslip->eis_employer, 0.0, (float) $priorPaid->sum('eis_employer')),
            ],
            'pcb' => [
                'employee' => $row(
                    (float) $payslip->pcb + (float) $payslip->pcb_additional,
                    (float) ($opening?->pcb_paid ?? 0),
                    (float) $priorPaid->sum(fn (Payslip $p) => $p->pcb + $p->pcb_additional),
                ),
            ],
            // No opening column for SKBBK — it did not exist before this feature.
            'skbbk' => [
                'employee' => $row((float) $payslip->skbbk_employee, 0.0, (float) $priorPaid->sum('skbbk_employee')),
            ],
        ];
    }
}
