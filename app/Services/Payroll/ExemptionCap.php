<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Spec F8: a Payroll Item may carry a yearly tax-exempt cap (PayrollItem
 * ::pcb_exempt_cap_yearly — e.g. LHDN's RM6,000/year official-duties travel allowance).
 * Pay through that item is exempt from PCB until the year's cap is used up, then fully
 * taxable. The month that crosses the cap is split.
 */
final class ExemptionCap
{
    /** How much of this month's amount is taxable. A null or zero cap means all of it. */
    public static function taxableThisMonth(float $amountThisMonth, float $usedEarlierThisYear, ?float $yearlyCap): float
    {
        if ($yearlyCap === null || $yearlyCap <= 0.0) {
            return round(max(0.0, $amountThisMonth), 2);
        }
        $remainingCap = max(0.0, $yearlyCap - max(0.0, $usedEarlierThisYear));

        return round(max(0.0, $amountThisMonth - $remainingCap), 2);
    }
}
