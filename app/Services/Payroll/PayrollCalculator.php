<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Pure payroll math — no DB, no framework. Given an employee's pay inputs, produces a
 * fully-costed PayslipComputation.
 *
 * Statutory model: EPF follows the KWSP Third Schedule (EpfCalculator) — a fixed
 * ringgit amount per wage band below RM20,000, not a percentage. SOCSO (SocsoCalculator)
 * and EIS (EisCalculator) follow the official PERKESO Third/Second Schedules
 * (PerkesoSchedule) — looked up by the employee's contribution category (1 = <60,
 * 2 = ≥60). Neither is tenant-editable; there is no flat-percentage fallback.
 */
class PayrollCalculator
{
    /** Employment Act ordinary-rate-of-pay defaults used for OT and unpaid-leave proration. */
    public const WORKING_DAYS_PER_MONTH = 26;

    public const WORKING_HOURS_PER_DAY = 8;

    public const OVERTIME_MULTIPLIER = 1.5;

    public function __construct(
        private readonly EpfCalculator $epf,
        private readonly SocsoCalculator $socso,
        private readonly EisCalculator $eis,
    ) {}

    /**
     * @param  array{
     *     basic?: float|int|string,
     *     allowances_total?: float|int|string,
     *     overtime_hours?: float|int|string,
     *     bonus?: float|int|string,
     *     additions?: array<int, array{name?: string, amount?: float|int|string}>,
     *     unpaid_days?: float|int|string,
     *     pcb?: float|int|string,
     *     pcb_additional?: float|int|string,
     *     zakat?: float|int|string,
     *     cp38?: float|int|string,
     *     pcb_override?: float|int|string|null,
     *     other_deductions?: array<int, array{name?: string, amount?: float|int|string}>,
     *     claims_reimbursement?: float|int|string,
     *     statutory_category?: int,
     *     epf_part?: string|null,
     *     skbbk_opt_in?: bool,
     *     lines?: array<int, array{amount?: float|int|string, epf_liable?: bool, perkeso_liable?: bool}>|null,
     *     overtime_flags?: array{epf_liable?: bool, perkeso_liable?: bool}|null,
     *     fixed_deductions_total?: float|int|string,
     *  }  $inputs
     */
    public function compute(array $inputs): PayslipComputation
    {
        // 1 = under 60 (full SOCSO + EIS); 2 = 60 and over (SOCSO Employment-Injury only, no EIS).
        $category = ((int) ($inputs['statutory_category'] ?? 1)) >= 2 ? 2 : 1;
        $basic = $this->money($inputs['basic'] ?? 0);
        $allowancesTotal = $this->money($inputs['allowances_total'] ?? 0);
        $overtimeHours = max(0.0, (float) ($inputs['overtime_hours'] ?? 0));
        $bonus = $this->money($inputs['bonus'] ?? 0);
        $unpaidDays = max(0.0, (float) ($inputs['unpaid_days'] ?? 0));
        $pcb = $this->money($inputs['pcb'] ?? 0);
        $pcbAdditional = $this->money($inputs['pcb_additional'] ?? 0);
        $zakat = $this->money($inputs['zakat'] ?? 0);
        $cp38 = $this->money($inputs['cp38'] ?? 0);
        $pcbOverride = isset($inputs['pcb_override']) && $inputs['pcb_override'] !== null && $inputs['pcb_override'] !== ''
            ? $this->money($inputs['pcb_override']) : null;
        $claimsReimbursement = $this->money($inputs['claims_reimbursement'] ?? 0);
        // Deduction-type Fixed Transactions (e.g. a recurring staff loan instalment) —
        // reduces net pay only, never the EPF/PERKESO wage bases (those are earnings
        // concepts; see the flag-derived $epfBase/$perkesoBase below, which this
        // deliberately does not feed into).
        $fixedDeductionsTotal = $this->money($inputs['fixed_deductions_total'] ?? 0);

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
        // The EPF and PERKESO (SOCSO+EIS) wage bases are sums over each pay-item's own
        // epf_liable/perkeso_liable flags (see PayrollItem/PayrollItemSeeder), not a
        // hardcoded formula: "wages" under s.2 of the EPF Act 1991 EXCLUDES overtime, while
        // PERKESO's published payments-subject-to-contribution list INCLUDES overtime but
        // EXCLUDES the annual bonus — two deliberately different wage bases from the same
        // gross pay. The unpaid-leave deduction reduces both bases equally.
        //
        // $inputs['lines'] carries the resolved flags for basic/allowances/bonus/additions
        // (amounts known ahead of time); overtime's amount is only known after computing it
        // above, so its flags travel separately in $inputs['overtime_flags']. Both must be
        // present (isset, not merely truthy — an intentionally empty lines array is not "no
        // catalogue data") for a caller to opt into flag-derived bases; otherwise this falls
        // back to the pre-catalogue hardcoded rule so every caller that doesn't pass a
        // catalogue (older code paths, unit tests exercising the calculator directly)
        // reproduces the exact same figures as before this pass.
        $epfPart = $inputs['epf_part'] ?? 'A';
        $catalogueLines = $inputs['lines'] ?? null;
        $overtimeFlags = $inputs['overtime_flags'] ?? null;
        if ($catalogueLines !== null && $overtimeFlags !== null) {
            $epfBase = ! empty($overtimeFlags['epf_liable']) ? $overtimeAmount : 0.0;
            $perkesoBase = ! empty($overtimeFlags['perkeso_liable']) ? $overtimeAmount : 0.0;
            foreach ($catalogueLines as $line) {
                $lineAmount = $this->money($line['amount'] ?? 0);
                if (! empty($line['epf_liable'])) {
                    $epfBase += $lineAmount;
                }
                if (! empty($line['perkeso_liable'])) {
                    $perkesoBase += $lineAmount;
                }
            }
            $epfWage = round(max(0.0, $epfBase - $unpaidDeduction), 2);
            $socsoWageFromLines = round(max(0.0, $perkesoBase - $unpaidDeduction), 2);
        } else {
            $epfWage = round(max(0.0, $statWage - $overtimeAmount), 2);
            $socsoWageFromLines = null;
        }
        $epfContribution = $this->epf->contribution($epfWage, $epfPart);
        $epfEmployee = $epfContribution['employee'];
        $epfEmployer = $epfContribution['employer'];

        $socsoWage = $socsoWageFromLines ?? round(max(0.0, $statWage - $bonus), 2);
        $skbbkOptIn = (bool) ($inputs['skbbk_opt_in'] ?? false);
        $socsoContribution = $this->socso->contribution($socsoWage, $category, $skbbkOptIn);
        $skbbkEmployee = $socsoContribution['skbbk'];
        // socso_employee is SOCSO proper (Invalidity for category 1) — SKBBK is its own
        // payslip line, not folded into this figure.
        $socsoEmployee = round($socsoContribution['employee'] - $skbbkEmployee, 2);
        $socsoEmployer = $socsoContribution['employer'];

        $eisContribution = $this->eis->contribution($socsoWage, $category);
        $eisEmployee = $eisContribution['employee'];
        $eisEmployer = $eisContribution['employer'];

        // A non-null override wins over the computed normal PCB verbatim — HR's manual
        // figure, not netted against zakat again (that netting already happened, or
        // didn't, in whatever the override represents).
        $pcbEffective = $pcbOverride ?? $pcb;

        $totalDeductions = round($epfEmployee + $socsoEmployee + $eisEmployee + $skbbkEmployee + $pcbEffective + $pcbAdditional + $zakat + $cp38 + $otherDeductionsTotal + $fixedDeductionsTotal, 2);
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
            skbbkEmployee: $skbbkEmployee,
            pcb: $pcbEffective,
            pcbAdditional: $pcbAdditional,
            zakat: $zakat,
            cp38: $cp38,
            pcbOverride: $pcbOverride,
            otherDeductions: $otherDeductions,
            otherDeductionsTotal: $otherDeductionsTotal,
            fixedDeductionsTotal: $fixedDeductionsTotal,
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
