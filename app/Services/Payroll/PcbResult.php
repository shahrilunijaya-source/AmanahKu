<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Result of one month's PCB calculation. Normal and additional remuneration MTD are kept
 * separate so a payslip can show them as distinct lines (LHDN's CP39 also reports them
 * separately); totalPayable is what actually gets deducted this month.
 *
 * chargeableIncomeP and k2 are exposed for audit trails and test verification — they are
 * the P and K2 intermediate values the spec's worked examples print.
 */
final readonly class PcbResult
{
    public function __construct(
        public float $normalMtd,
        public float $netNormalMtd,
        public float $additionalMtd,
        public float $totalPayable,
        public float $chargeableIncomeP,
        public float $k2,
    ) {}
}
