<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Pure payroll math — no DB, no framework. Given an employee's pay inputs and the
 * active statutory rate tables, produces a fully-costed PayslipComputation.
 *
 * Statutory model: EPF follows the KWSP Third Schedule (EpfCalculator) — a fixed
 * ringgit amount per wage band below RM20,000, not a percentage. SOCSO/EIS use the
 * PERKESO stepped bracket tables (StatutoryBrackets) when `use_brackets` is set on
 * their rate config — looked up by the employee's contribution category (1 = <60,
 * 2 = ≥60). When `use_brackets` is absent/false the legacy flat-percentage-on-capped-wage
 * path is used (kept for tenants that override to a flat rate, and an approximation
 * flagged in ISSUES).
 */
class PayrollCalculator
{
    /** Employment Act ordinary-rate-of-pay defaults used for OT and unpaid-leave proration. */
    public const WORKING_DAYS_PER_MONTH = 26;

    public const WORKING_HOURS_PER_DAY = 8;

    public const OVERTIME_MULTIPLIER = 1.5;

    public function __construct(private readonly EpfCalculator $epf) {}

    /**
     * @param  array{
     *     basic?: float|int|string,
     *     allowances_total?: float|int|string,
     *     overtime_hours?: float|int|string,
     *     bonus?: float|int|string,
     *     additions?: array<int, array{name?: string, amount?: float|int|string}>,
     *     unpaid_days?: float|int|string,
     *     pcb?: float|int|string,
     *     other_deductions?: array<int, array{name?: string, amount?: float|int|string}>,
     *     claims_reimbursement?: float|int|string,
     *     statutory_category?: int,
     *     epf_part?: string|null,
     *  }  $inputs
     * @param  array{socso: array<string, mixed>, eis: array<string, mixed>}  $rates
     */
    public function compute(array $inputs, array $rates): PayslipComputation
    {
        // 1 = under 60 (full SOCSO + EIS); 2 = 60 and over (SOCSO Employment-Injury only, no EIS).
        $category = ((int) ($inputs['statutory_category'] ?? 1)) >= 2 ? 2 : 1;
        $basic = $this->money($inputs['basic'] ?? 0);
        $allowancesTotal = $this->money($inputs['allowances_total'] ?? 0);
        $overtimeHours = max(0.0, (float) ($inputs['overtime_hours'] ?? 0));
        $bonus = $this->money($inputs['bonus'] ?? 0);
        $unpaidDays = max(0.0, (float) ($inputs['unpaid_days'] ?? 0));
        $pcb = $this->money($inputs['pcb'] ?? 0);
        $claimsReimbursement = $this->money($inputs['claims_reimbursement'] ?? 0);

        $additions = $this->cleanLines($inputs['additions'] ?? []);
        $otherDeductions = $this->cleanLines($inputs['other_deductions'] ?? []);
        $additionsTotal = $this->sumLines($additions);
        $otherDeductionsTotal = $this->sumLines($otherDeductions);

        // Earnings.
        $dailyRate = $basic / self::WORKING_DAYS_PER_MONTH;
        $hourlyRate = $dailyRate / self::WORKING_HOURS_PER_DAY;
        $overtimeAmount = round($overtimeHours * $hourlyRate * self::OVERTIME_MULTIPLIER, 2);
        $unpaidDeduction = round($unpaidDays * $dailyRate, 2);

        // Gross floors at zero — unpaid leave can't drive earnings negative.
        $gross = round(max(0.0, $basic + $allowancesTotal + $overtimeAmount + $bonus + $additionsTotal - $unpaidDeduction), 2);
        $statWage = $gross;

        // EPF — KWSP Third Schedule wage bands (EpfCalculator), not a flat percentage.
        // Callers that don't pass epf_part (e.g. older code paths) default to Part A —
        // the common case (citizen/PR under 60) — rather than silently contributing nothing.
        //
        // "Wages" under s.2 of the EPF Act 1991 EXCLUDES overtime, so EPF is charged on the
        // month's pay less the overtime amount. Bonus and commission are wages and stay in.
        // Free-form allowance and addition lines are treated as wages (the safe default)
        // until the pay-item catalogue can flag each line's statutory treatment.
        $epfPart = $inputs['epf_part'] ?? 'A';
        $epfWage = round(max(0.0, $statWage - $overtimeAmount), 2);
        $epfContribution = $this->epf->contribution($epfWage, $epfPart);
        $epfEmployee = $epfContribution['employee'];
        $epfEmployer = $epfContribution['employer'];

        // SOCSO + EIS — official PERKESO stepped brackets when enabled, else flat-% fallback.
        $socso = $rates['socso'];
        if (! empty($socso['use_brackets'])) {
            $base = min($statWage, StatutoryBrackets::WAGE_CEILING);
            $row = StatutoryBrackets::lookup(StatutoryBrackets::socso($category), $base);
            $socsoEmployee = $row['ee'];
            $socsoEmployer = $row['er'];
        } else {
            $socsoBase = min($statWage, (float) $socso['wage_ceiling']);
            $socsoEmployee = round($socsoBase * (float) $socso['employee_pct'] / 100, 2);
            $socsoEmployer = round($socsoBase * (float) $socso['employer_pct'] / 100, 2);
        }

        $eis = $rates['eis'];
        if (! empty($eis['use_brackets'])) {
            $base = min($statWage, StatutoryBrackets::WAGE_CEILING);
            // Category 2 (≥60) is not covered by EIS — socso()/eis() return an empty schedule → zero.
            $row = StatutoryBrackets::lookup(StatutoryBrackets::eis($category), $base);
            $eisEmployee = $row['ee'];
            $eisEmployer = $row['er'];
        } else {
            $eisBase = min($statWage, (float) $eis['wage_ceiling']);
            $eisEmployee = round($eisBase * (float) $eis['employee_pct'] / 100, 2);
            $eisEmployer = round($eisBase * (float) $eis['employer_pct'] / 100, 2);
        }

        $totalDeductions = round($epfEmployee + $socsoEmployee + $eisEmployee + $pcb + $otherDeductionsTotal, 2);
        $netPay = round($statWage - $totalDeductions + $claimsReimbursement, 2);
        $employerCost = round($statWage + $epfEmployer + $socsoEmployer + $eisEmployer, 2);

        return new PayslipComputation(
            basic: $basic,
            allowancesTotal: $allowancesTotal,
            overtimeHours: round($overtimeHours, 2),
            overtimeAmount: $overtimeAmount,
            bonus: $bonus,
            additions: $additions,
            additionsTotal: $additionsTotal,
            unpaidDays: round($unpaidDays, 2),
            unpaidDeduction: $unpaidDeduction,
            gross: $gross,
            epfEmployee: $epfEmployee,
            epfEmployer: $epfEmployer,
            socsoEmployee: $socsoEmployee,
            socsoEmployer: $socsoEmployer,
            eisEmployee: $eisEmployee,
            eisEmployer: $eisEmployer,
            pcb: $pcb,
            otherDeductions: $otherDeductions,
            otherDeductionsTotal: $otherDeductionsTotal,
            claimsReimbursement: $claimsReimbursement,
            totalDeductions: $totalDeductions,
            netPay: $netPay,
            employerCost: $employerCost,
        );
    }

    private function money(float|int|string $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    /**
     * Normalise free-form line items to [{name, amount}] with positive amounts only.
     *
     * @param  array<int, array{name?: string, amount?: float|int|string}>  $lines
     * @return array<int, array{name: string, amount: float}>
     */
    private function cleanLines(array $lines): array
    {
        $clean = [];
        foreach ($lines as $line) {
            $amount = $this->money($line['amount'] ?? 0);
            $name = trim((string) ($line['name'] ?? ''));
            if ($name === '' || $amount <= 0) {
                continue;
            }
            $clean[] = ['name' => $name, 'amount' => $amount];
        }

        return $clean;
    }

    /** @param array<int, array{name: string, amount: float}> $lines */
    private function sumLines(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }
}
