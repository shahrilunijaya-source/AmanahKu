<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * PCB / MTD (Monthly Tax Deduction) — LHDN Computerized Calculation Method, 2026.
 *
 * Implements the full year-to-date method from docs/statutory/spesifikasi-kaedah-
 * pengiraan-berkomputer-pcb-2026.pdf: section D for the normal- and additional-
 * remuneration formulas, section E for rounding/truncation and the under-RM10 rule.
 * Verified end to end against Exhibit 5's four-month worked example (see
 * tests/Unit/PcbCalculatorTest.php) — every intermediate P and K2 value it prints
 * is reproduced exactly.
 *
 * Only the normal-remuneration and additional-remuneration formulas (D.b.1 and D.b.2)
 * and the non-resident flat rate (D.a) are implemented. REP, Knowledge Worker, and
 * C-Suite categories (D.b.3–5) are separate, rarer regimes not covered here — add them
 * the same way if a tenant needs them.
 */
final class PcbCalculator
{
    /** Non-resident MTD: a flat percentage of remuneration, no reliefs (spec D.a). */
    private const NON_RESIDENT_RATE = 0.30;

    /** Annual cap on EPF/approved-scheme relief that feeds K2 (spec E.14.i.d). */
    private const EPF_RELIEF_CAP = 4000.0;

    /** Fixed reliefs, spec E.14.i (a, b, e, f). Per-child (Q) is applied separately. */
    private const INDIVIDUAL_RELIEF = 9000.0;

    private const SPOUSE_RELIEF = 4000.0;

    private const DISABLED_INDIVIDUAL_RELIEF = 7000.0;

    private const DISABLED_SPOUSE_RELIEF = 6000.0;

    private const CHILD_RELIEF = 2000.0;

    /** Below this, no deduction is made at all (spec E.3/E.4). */
    private const NO_DEDUCTION_THRESHOLD = 10.0;

    /**
     * Table 1 (spec D.b.1): [upper P bound, M, R%, B for category 1 & 3, B for category 2].
     * The implicit band below RM5,001 has no entry — P at or under RM5,000 owes no MTD.
     *
     * @var array<int, array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    private const TABLE_1 = [
        [20000, 5000, 1, -400, -800],
        [35000, 20000, 3, -250, -650],
        [50000, 35000, 6, 600, 600],
        [70000, 50000, 11, 1500, 1500],
        [100000, 70000, 19, 3700, 3700],
        [400000, 100000, 25, 9400, 9400],
        [600000, 400000, 26, 84400, 84400],
        [2000000, 600000, 28, 136400, 136400],
        [PHP_INT_MAX, 2000000, 30, 528400, 528400],
    ];

    public function calculate(PcbInputs $in): PcbResult
    {
        if (! $in->isResident) {
            return $this->calculateNonResident($in);
        }

        $reliefs = $this->reliefs($in);
        $n = $in->monthsRemainingAfterCurrent;

        // Step 1 — MTD on normal remuneration only (Yt – Kt excluded, per the spec's own
        // worked example: "Where (Yt – Kt) = 0"). This is the whole calculation when there
        // is no additional remuneration this month.
        $k2Normal = $this->k2($in->ytdEpfK, $in->currentEpfK1, 0.0, $n);
        $pNormal = $this->truncate(
            ($in->ytdGrossY - $in->ytdEpfK)
            + ($in->currentGrossY1 - $in->currentEpfK1)
            + ($in->currentGrossY1 - $k2Normal) * $n
            - $reliefs
        );
        $normalMtdRaw = $this->truncate($this->taxOn($pNormal, $in->category, $n, $in->ytdZakatZ + $in->ytdMtdPaidX));
        $normalMtd = $this->applyThreshold($normalMtdRaw);
        $netNormalMtd = max(0.0, $normalMtd - $in->currentZakat);

        if ($in->currentAdditionalGrossYt === 0.0 && $in->currentAdditionalEpfKt === 0.0) {
            return new PcbResult($normalMtd, $netNormalMtd, 0.0, $netNormalMtd, $pNormal, $k2Normal);
        }

        // Step 1[E] — projected MTD for the year on normal remuneration alone.
        $totalMtdForYearNormal = $in->ytdMtdPaidX + $normalMtd * ($n + 1);

        // Step 2 — chargeable income for the year including this month's additional
        // remuneration; K2 is recomputed with Kt now in the EPF pool (spec E.13.iii).
        $k2Combined = $this->k2($in->ytdEpfK, $in->currentEpfK1, $in->currentAdditionalEpfKt, $n);
        $pCombined = $this->truncate(
            ($in->ytdGrossY - $in->ytdEpfK)
            + ($in->currentGrossY1 - $in->currentEpfK1)
            + ($in->currentGrossY1 - $k2Combined) * $n
            + ($in->currentAdditionalGrossYt - $in->currentAdditionalEpfKt)
            - $reliefs
        );

        // Step 3 — total tax for the year on the combined chargeable income (no ÷(n+1), no Z/X).
        [$m, $r, $b] = $this->bandFor($pCombined, $in->category);
        $totalTaxForYear = $this->truncate(($pCombined - $m) * $r / 100 + $b);

        // Step 4 — the additional-remuneration MTD is the difference, plus zakat already paid.
        $additionalMtdRaw = $this->truncate($totalTaxForYear - ($totalMtdForYearNormal + $in->ytdZakatZ));
        $additionalMtd = $this->applyThreshold($additionalMtdRaw);

        // Step 5 — what actually gets deducted this month.
        $totalPayable = $netNormalMtd + $additionalMtd;

        return new PcbResult($normalMtd, $netNormalMtd, $additionalMtd, $totalPayable, $pCombined, $k2Combined);
    }

    /** Non-resident: flat 30% of remuneration, no reliefs, no P/M/R/B table (spec D.a). */
    private function calculateNonResident(PcbInputs $in): PcbResult
    {
        $normalMtd = round($in->currentGrossY1 * self::NON_RESIDENT_RATE, 2);
        $additionalMtd = round($in->currentAdditionalGrossYt * self::NON_RESIDENT_RATE, 2);
        $netNormalMtd = max(0.0, $normalMtd - $in->currentZakat);

        return new PcbResult($normalMtd, $netNormalMtd, $additionalMtd, $netNormalMtd + $additionalMtd, 0.0, 0.0);
    }

    /**
     * K2 — estimated EPF/approved-scheme contribution for each of the remaining months:
     * min( (4,000 − (K + K1 + Kt)) / n , K1 ), never negative (spec D.b.1, "Where" note).
     */
    private function k2(float $ytdEpfK, float $currentEpfK1, float $kt, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }

        $estimate = $this->truncate((self::EPF_RELIEF_CAP - ($ytdEpfK + $currentEpfK1 + $kt)) / $n);

        return max(0.0, min($estimate, $currentEpfK1));
    }

    /** [D + S + DU + SU + QC + (∑LP + LP1)] — spec E.14.i, category rules in D.b.1. */
    private function reliefs(PcbInputs $in): float
    {
        // Category 1 (single) has no spouse and, per the spec's own category definition,
        // no child relief through this formula (S = 0, C = 0) — category 3 covers a single
        // parent with an adopted child instead.
        $children = $in->category === 1 ? 0 : $in->qualifyingChildren;
        $spouse = $in->category === 2 ? self::SPOUSE_RELIEF : 0.0;

        return self::INDIVIDUAL_RELIEF
            + $spouse
            + ($in->disabledIndividual ? self::DISABLED_INDIVIDUAL_RELIEF : 0.0)
            + ($in->disabledSpouse ? self::DISABLED_SPOUSE_RELIEF : 0.0)
            + self::CHILD_RELIEF * $children
            + $in->ytdOptionalDeductions + $in->currentOptionalDeductions;
    }

    /**
     * [(P – M) R + B – (Z + X)] / (n+1) — used for the normal MTD only.
     *
     * The spec's own prose formula reads "[(P – M) R + B] / (n+1) – (Z + X)", which places
     * (Z + X) outside the division. But Exhibit 5's worked examples only reproduce (e.g.
     * February: RM47,000 P, RM110 paid in January, answer RM110) when (Z + X) is subtracted
     * INSIDE the division — confirmed by re-deriving the fraction bar's actual span in the
     * PDF's own layout. Followed the worked numbers over the prose per the task brief.
     */
    private function taxOn(float $p, int $category, int $n, float $zPlusX): float
    {
        [$m, $r, $b] = $this->bandFor($p, $category);

        return (($p - $m) * $r / 100 + $b - $zPlusX) / ($n + 1);
    }

    /** @return array{0: float, 1: float, 2: float} [M, R, B] */
    private function bandFor(float $p, int $category): array
    {
        if ($p <= 5000.0) {
            return [0.0, 0.0, 0.0];
        }

        foreach (self::TABLE_1 as [$upper, $m, $r, $bCat13, $bCat2]) {
            if ($p <= $upper) {
                return [$m, $r, $category === 2 ? $bCat2 : $bCat13];
            }
        }

        // Unreachable: the last band's upper bound is PHP_INT_MAX.
        return [0.0, 0.0, 0.0];
    }

    /**
     * Spec E.3/E.4: below RM10, no deduction; at or above, round up to the nearest 5 or 10
     * cents (spec E.2). Working in nickels (×20) turns both cent-rounding cases into one
     * ceiling: 1–4c → 5c and 6–9c → 10c are both "round up to the next multiple of 5c".
     */
    private function applyThreshold(float $amount): float
    {
        if ($amount < self::NO_DEDUCTION_THRESHOLD) {
            return 0.0;
        }

        $nickels = round($amount * 20, 6);

        return ceil($nickels - 1e-9) / 20;
    }

    /** Spec E.1: truncate to 2 decimals, don't round. 123.4534 → 123.45. */
    private function truncate(float $value): float
    {
        $scaled = round($value * 100, 6);
        $sign = $scaled < 0 ? -1.0 : 1.0;

        return $sign * floor(abs($scaled) + 1e-9) / 100;
    }
}
