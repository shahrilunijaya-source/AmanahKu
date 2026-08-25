<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Immutable result of a payslip calculation. Carries every line a payslip needs,
 * already rounded to 2 decimals. Map to the payslips table via toPayslipAttributes().
 */
final readonly class PayslipComputation
{
    /**
     * @param  array<int, array{name: string, amount: float}>  $additions
     * @param  array<int, array{name: string, amount: float}>  $otherDeductions
     * @param  array<int, array{hours: float, multiplier: float, amount: float}>  $overtimeGroups
     */
    public function __construct(
        public float $basic,
        public float $allowancesTotal,
        public float $overtimeHours,
        public float $overtimeAmount,
        public array $overtimeGroups,
        public float $bonus,
        public array $additions,
        public float $additionsTotal,
        public float $unpaidDays,
        public float $unpaidDeduction,
        public float $gross,
        public float $epfEmployee,
        public float $epfEmployer,
        public float $socsoEmployee,
        public float $socsoEmployer,
        public float $eisEmployee,
        public float $eisEmployer,
        public float $skbbkEmployee,
        public float $pcb,
        public float $pcbAdditional,
        public float $zakat,
        public float $cp38,
        public ?float $pcbOverride,
        public array $otherDeductions,
        public float $otherDeductionsTotal,
        public float $fixedDeductionsTotal,
        public float $claimsReimbursement,
        public float $totalDeductions,
        public float $netPay,
        public float $employerCost,
    ) {}

    /** Total employee-side statutory contributions (EPF + SOCSO + EIS + SKBBK). */
    public function statutoryEmployee(): float
    {
        return round($this->epfEmployee + $this->socsoEmployee + $this->eisEmployee + $this->skbbkEmployee, 2);
    }

    /** Total employer-side statutory contributions. */
    public function statutoryEmployer(): float
    {
        return round($this->epfEmployer + $this->socsoEmployer + $this->eisEmployer, 2);
    }

    /** Column map for Payslip::create()/update() (claim_ids set separately by caller). */
    public function toPayslipAttributes(): array
    {
        return [
            'basic' => $this->basic,
            'allowances_total' => $this->allowancesTotal,
            'overtime_hours' => $this->overtimeHours,
            'overtime_amount' => $this->overtimeAmount,
            // Null when the pull mixed more than one rate — no single multiplier
            // describes that payslip; the PayslipLine rows (one per rate) are the
            // source of truth for the breakdown either way.
            'overtime_multiplier' => count($this->overtimeGroups) === 1 ? $this->overtimeGroups[0]['multiplier'] : null,
            'bonus' => $this->bonus,
            'additions' => $this->additions ?: null,
            'unpaid_days' => $this->unpaidDays,
            'unpaid_deduction' => $this->unpaidDeduction,
            'gross' => $this->gross,
            'epf_employee' => $this->epfEmployee,
            'epf_employer' => $this->epfEmployer,
            'socso_employee' => $this->socsoEmployee,
            'socso_employer' => $this->socsoEmployer,
            'eis_employee' => $this->eisEmployee,
            'eis_employer' => $this->eisEmployer,
            'skbbk_employee' => $this->skbbkEmployee,
            'pcb' => $this->pcb,
            'pcb_additional' => $this->pcbAdditional,
            'zakat' => $this->zakat,
            'cp38' => $this->cp38,
            'pcb_override' => $this->pcbOverride,
            'other_deductions' => $this->otherDeductions ?: null,
            'fixed_deductions_total' => $this->fixedDeductionsTotal,
            'claims_reimbursement' => $this->claimsReimbursement,
            'total_deductions' => $this->totalDeductions,
            'net_pay' => $this->netPay,
            'employer_cost' => $this->employerCost,
        ];
    }
}
