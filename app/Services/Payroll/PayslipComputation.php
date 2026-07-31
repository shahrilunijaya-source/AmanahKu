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
     */
    public function __construct(
        public float $basic,
        public float $allowancesTotal,
        public float $overtimeHours,
        public float $overtimeAmount,
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
        public float $pcb,
        public array $otherDeductions,
        public float $otherDeductionsTotal,
        public float $claimsReimbursement,
        public float $totalDeductions,
        public float $netPay,
        public float $employerCost,
    ) {}

    /** Total employee-side statutory contributions (EPF + SOCSO + EIS). */
    public function statutoryEmployee(): float
    {
        return round($this->epfEmployee + $this->socsoEmployee + $this->eisEmployee, 2);
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
            'pcb' => $this->pcb,
            'other_deductions' => $this->otherDeductions ?: null,
            'claims_reimbursement' => $this->claimsReimbursement,
            'total_deductions' => $this->totalDeductions,
            'net_pay' => $this->netPay,
            'employer_cost' => $this->employerCost,
        ];
    }
}
