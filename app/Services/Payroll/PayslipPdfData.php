<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Payslip;
use App\Models\PayslipLine;
use Illuminate\Support\Collection;

/**
 * Assembles one payslip's worth of PDF view data: the EARNINGS/DEDUCTIONS rows
 * (Description/Period/Rate/Total — see rows() below for what each means) and the
 * STATUTORY SUMMARY figures from PayslipYearToDate. Kept out of the Blade view so the
 * "PayslipLine vs legacy lumped columns" fallback logic has one tested home.
 */
final class PayslipPdfData
{
    public function __construct(private readonly PayslipYearToDate $ytd) {}

    /** @return array<string, mixed> */
    public function build(Payslip $payslip): array
    {
        $earnings = $this->rows($payslip, 'earning');
        // Statutory contributions (EPF/SOCSO/EIS/SKBBK/PCB/zakat/CP38) are deliberately
        // never PayslipLine rows (see the class docblock on Payslip) — they live only as
        // computed columns, so the deductions table must fold them in here regardless of
        // whether this payslip has itemised lines or falls back to the legacy columns.
        // Without this, TOTAL DEDUCTIONS silently excludes every statutory amount and the
        // printed EARNINGS/DEDUCTIONS/NETT figures stop agreeing with each other.
        $deductions = $this->statutoryRows($payslip)->concat($this->rows($payslip, 'deduction'))->values();

        return [
            'payslip' => $payslip,
            'employee' => $payslip->employee,
            'run' => $payslip->payrollRun,
            'structure' => $payslip->employee?->salaryStructure,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'totalEarnings' => round($earnings->sum('total'), 2),
            'totalDeductions' => round($deductions->sum('total'), 2),
            // Claim reimbursement adds to net pay AFTER deductions (PayrollCalculator::compute:
            // netPay = gross - totalDeductions + claimsReimbursement) — it is never part of
            // gross/TOTAL EARNINGS, so it is surfaced as its own figure rather than folded in.
            'reimbursement' => round((float) $payslip->claims_reimbursement, 2),
            'ytd' => $this->ytd->forPayslip($payslip),
        ];
    }

    /**
     * @param  'earning'|'deduction'  $type
     * @return Collection<int, array{description: string, period: string, rate: string, total: float}>
     */
    private function rows(Payslip $payslip, string $type): Collection
    {
        if ($payslip->lines->isNotEmpty()) {
            return $payslip->lines->where('type', $type)
                // The claim-reimbursement line (source 'claim') is excluded here too — see
                // the 'reimbursement' comment in build() above.
                ->reject(fn (PayslipLine $line) => $line->source === 'claim')
                ->values()
                ->map(fn ($line) => $this->rowFromLine($line));
        }

        // Payslips issued before PayslipLine existed — rebuild an equivalent row set
        // from the lumped columns (mirrors the in-app payslip detail view).
        return $type === 'earning' ? $this->legacyEarningRows($payslip) : $this->legacyDeductionRows($payslip);
    }

    /** @return Collection<int, array{description: string, period: string, rate: string, total: float}> */
    private function statutoryRows(Payslip $payslip): Collection
    {
        $rows = collect([
            ['description' => 'EPF Employee Contribution', 'period' => '', 'rate' => '', 'total' => (float) $payslip->epf_employee],
            ['description' => 'SOCSO Employee Contribution', 'period' => '', 'rate' => '', 'total' => (float) $payslip->socso_employee],
            ['description' => 'EIS Employee Contribution', 'period' => '', 'rate' => '', 'total' => (float) $payslip->eis_employee],
        ]);
        if ($payslip->skbbk_employee > 0) {
            $rows->push(['description' => 'SKBBK (Lindung 24 Jam)', 'period' => '', 'rate' => '', 'total' => (float) $payslip->skbbk_employee]);
        }
        $rows->push(['description' => 'PCB (Income Tax)', 'period' => '', 'rate' => '', 'total' => (float) $payslip->pcb]);
        if ($payslip->pcb_additional > 0) {
            $rows->push(['description' => 'PCB (Bonus / Additional)', 'period' => '', 'rate' => '', 'total' => (float) $payslip->pcb_additional]);
        }
        if ($payslip->zakat > 0) {
            $rows->push(['description' => 'Zakat', 'period' => '', 'rate' => '', 'total' => (float) $payslip->zakat]);
        }
        if ($payslip->cp38 > 0) {
            $rows->push(['description' => 'CP38', 'period' => '', 'rate' => '', 'total' => (float) $payslip->cp38]);
        }

        return $rows;
    }

    /** @return array{description: string, period: string, rate: string, total: float} */
    private function rowFromLine(PayslipLine $line): array
    {
        $quantity = $line->quantity !== null ? rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') : null;

        if ($line->source === 'overtime') {
            // Name is written as "Overtime {multiplier}×" (PayrollController::variableLineAttrs)
            // — pull the multiplier back out so it lands in Rate, hours stay in Period.
            preg_match('/([\d.]+)×/', $line->name, $m);

            return [
                'description' => $line->name,
                'period' => $quantity !== null ? $quantity.' hrs' : '',
                'rate' => isset($m[1]) ? $m[1].'×' : '',
                'total' => (float) $line->amount,
            ];
        }

        $unitRate = $quantity !== null && (float) $line->quantity > 0
            ? number_format((float) $line->amount / (float) $line->quantity, 2)
            : '';

        return [
            'description' => $line->name.($line->remark ? " ({$line->remark})" : ''),
            'period' => $quantity !== null ? $quantity : '',
            'rate' => $unitRate,
            'total' => (float) $line->amount,
        ];
    }

    /** @return Collection<int, array{description: string, period: string, rate: string, total: float}> */
    private function legacyEarningRows(Payslip $payslip): Collection
    {
        $rows = collect([['description' => 'Basic Salary', 'period' => '', 'rate' => '', 'total' => (float) $payslip->basic]]);

        if ($payslip->allowances_total > 0) {
            $rows->push(['description' => 'Allowances', 'period' => '', 'rate' => '', 'total' => (float) $payslip->allowances_total]);
        }
        if ($payslip->overtime_amount > 0) {
            $hours = rtrim(rtrim(number_format((float) $payslip->overtime_hours, 2), '0'), '.');
            $rows->push([
                'description' => 'Overtime',
                'period' => $hours.' hrs',
                'rate' => $payslip->overtime_multiplier ? rtrim(rtrim(number_format((float) $payslip->overtime_multiplier, 2), '0'), '.').'×' : '',
                'total' => (float) $payslip->overtime_amount,
            ]);
        }
        if ($payslip->bonus > 0) {
            $rows->push(['description' => 'Bonus', 'period' => '', 'rate' => '', 'total' => (float) $payslip->bonus]);
        }
        foreach (($payslip->additions ?? []) as $add) {
            $rows->push(['description' => (string) $add['name'], 'period' => '', 'rate' => '', 'total' => (float) $add['amount']]);
        }
        // Claim reimbursement is deliberately NOT included here — see the 'reimbursement'
        // comment in build().

        return $rows;
    }

    /** @return Collection<int, array{description: string, period: string, rate: string, total: float}> */
    private function legacyDeductionRows(Payslip $payslip): Collection
    {
        $rows = collect();

        if ($payslip->unpaid_deduction > 0) {
            $days = rtrim(rtrim(number_format((float) $payslip->unpaid_days, 2), '0'), '.');
            $rows->push([
                'description' => 'Unpaid Leave Deduction',
                'period' => $days.' days',
                'rate' => $payslip->unpaid_days > 0 ? number_format((float) $payslip->unpaid_deduction / (float) $payslip->unpaid_days, 2) : '',
                'total' => (float) $payslip->unpaid_deduction,
            ]);
        }
        foreach (($payslip->other_deductions ?? []) as $ded) {
            $rows->push(['description' => (string) $ded['name'], 'period' => '', 'rate' => '', 'total' => (float) $ded['amount']]);
        }

        return $rows;
    }
}
