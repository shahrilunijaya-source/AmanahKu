<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * PCB / MTD (Monthly Tax Deduction) estimator — resident individual.
 *
 * ⚠️ SIMPLIFIED ANNUALISED METHOD. This annualises the month's taxable income, applies
 * the progressive resident bands less reliefs, and divides by 12. It does NOT implement
 * LHDN's full Computerised Calculation Method (accumulated remuneration, prior MTD paid,
 * zakat, optional reliefs, additional remuneration formula). Use it as an ESTIMATE that
 * HR reviews — PCB remains overridable per payslip, and auto-PCB is OFF by default.
 * Verify against the official LHDN MTD spec / PCB calculator before relying on it (I-016).
 *
 * Bands below are the YA2024 resident individual chargeable-income rates (public LHDN
 * schedule). Editable via the rate config; treated as a default, not gospel.
 */
final class PcbCalculator
{
    public const IS_PLACEHOLDER = true;

    /** Standard individual relief (RM9,000) used when no relief override is supplied. */
    public const DEFAULT_INDIVIDUAL_RELIEF = 9000.0;

    /** EPF/life-insurance relief cap (RM4,000) applied to annual employee EPF. */
    public const DEFAULT_EPF_RELIEF_CAP = 4000.0;

    /**
     * Resident individual bands: [upper chargeable-income bound, marginal rate %].
     * The final entry uses PHP_INT_MAX as an open top band.
     *
     * @var array<int, array{0: float, 1: float}>
     */
    public const BANDS = [
        [5000, 0],
        [20000, 1],
        [35000, 3],
        [50000, 6],
        [70000, 11],
        [100000, 19],
        [400000, 25],
        [600000, 26],
        [2000000, 28],
        [PHP_INT_MAX, 30],
    ];

    /** Rebate for low chargeable income (RM400 when chargeable ≤ RM35,000). */
    private const LOW_INCOME_REBATE_THRESHOLD = 35000.0;

    private const LOW_INCOME_REBATE = 400.0;

    /** Progressive annual tax on a chargeable income, before rebates. */
    public function annualTax(float $chargeable): float
    {
        $chargeable = max(0.0, $chargeable);
        $tax = 0.0;
        $lower = 0.0;

        foreach (self::BANDS as [$upper, $rate]) {
            if ($chargeable <= $lower) {
                break;
            }
            $slice = min($chargeable, (float) $upper) - $lower;
            $tax += $slice * ($rate / 100);
            $lower = (float) $upper;
        }

        return round($tax, 2);
    }

    /**
     * Estimated monthly PCB for a given monthly taxable income.
     *
     * @param  float  $monthlyTaxable  This month's taxable pay (e.g. gross).
     * @param  float  $annualRelief  Total annual relief to deduct (individual + EPF + …).
     * @param  array{rebate?: bool}  $opts
     */
    public function monthlyEstimate(float $monthlyTaxable, float $annualRelief, array $opts = []): float
    {
        $annualIncome = max(0.0, $monthlyTaxable) * 12;
        $chargeable = max(0.0, $annualIncome - max(0.0, $annualRelief));

        $annualTax = $this->annualTax($chargeable);

        if (($opts['rebate'] ?? true) && $chargeable > 0 && $chargeable <= self::LOW_INCOME_REBATE_THRESHOLD) {
            $annualTax = max(0.0, $annualTax - self::LOW_INCOME_REBATE);
        }

        return round(max(0.0, $annualTax) / 12, 2);
    }

    /**
     * Convenience: annual relief from the standard individual relief plus capped annual
     * employee EPF. Mirrors how HR would build the relief figure for the estimate.
     */
    public function standardAnnualRelief(float $monthlyEpfEmployee, ?float $individualRelief = null, ?float $epfCap = null): float
    {
        $individual = $individualRelief ?? self::DEFAULT_INDIVIDUAL_RELIEF;
        $cap = $epfCap ?? self::DEFAULT_EPF_RELIEF_CAP;

        return round($individual + min($monthlyEpfEmployee * 12, $cap), 2);
    }
}
