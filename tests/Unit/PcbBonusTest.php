<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\PcbCalculator;
use App\Services\Payroll\PcbInputs;
use PHPUnit\Framework\TestCase;

/**
 * Spec F10: a bonus is additional remuneration — taxed with the spec's D.b.2 method
 * (docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf), not as extra
 * normal pay. A bonus run computes exactly this figure and stores it in pcb_additional.
 */
class PcbBonusTest extends TestCase
{
    /**
     * RM5,000 bonus in June for a single (category 1) resident on RM4,000 a month,
     * EPF employee 11% (RM440 a month, RM550 on the bonus), no zakat, no children,
     * no TP1 claims. Hand-worked month by month with the Exhibit method, all figures
     * truncated to 2 decimals (spec E.1) and MTD rounded up to the next 5 sen (E.2),
     * nothing below RM10 deducted (E.3):
     *
     *   Jan  n=11  K2 = min((4000-440)/11, 440)      = 323.63
     *              P  = 3560 + (4000-323.63)*11 - 9000            = 35,000.07
     *              MTD = ((35000.07-35000)*6% + 600 - 0)/12       = 50.00
     *   Feb  n=10  K2 = min((4000-880)/10, 440)      = 312.00
     *              P  = 3560 + 3560 + (4000-312)*10 - 9000        = 35,000.00
     *              MTD = ((35000-20000)*3% - 250 - 50)/11 = 13.63 → 13.65
     *   Mar  n=9   K2 = min((4000-1320)/9, 440)      = 297.77
     *              P  = 7120 + 3560 + (4000-297.77)*9 - 9000      = 35,000.07
     *              MTD = (0.0042 + 600 - 63.65)/10 = 53.63        → 53.65
     *   Apr  n=8   K2 = min((4000-1760)/8, 440)      = 280.00
     *              P  = 10680 + 3560 + (4000-280)*8 - 9000        = 35,000.00
     *              MTD = (450 - 250 - 117.30)/9 = 9.18            → 0.00 (under RM10)
     *   May  n=7   K2 = min((4000-2200)/7, 440)      = 257.14
     *              P  = 14240 + 3560 + (4000-257.14)*7 - 9000     = 35,000.02
     *              MTD = (0.0012 + 600 - 117.30)/8 = 60.33        → 60.35
     *
     * June therefore opens with ∑Y = 20,000, ∑K = 2,200, X = 177.65, n = 6:
     *
     *   Normal      K2 = min((4000-2640)/6, 440)     = 226.66
     *               P  = 17800 + 3560 + (4000-226.66)*6 - 9000    = 35,000.04
     *               MTD = (0.0024 + 600 - 177.65)/7 = 60.33       → 60.35
     *   Step 1[E]   total MTD for the year on normal pay = 177.65 + 60.35*7 = 600.10
     *   Combined    K2 = min((4000-3190)/6, 440)      = 135.00
     *               P  = 17800 + 3560 + (4000-135)*6 + (5000-550) - 9000 = 40,000.00
     *   Step 3      total tax for the year = (40000-35000)*6% + 600         = 900.00
     *   Step 4      additional MTD = 900.00 - 600.10                        = 299.90
     */
    public function test_june_bonus_of_five_thousand_on_a_four_thousand_salary(): void
    {
        $result = (new PcbCalculator)->calculate(new PcbInputs(
            category: 1,
            ytdGrossY: 20000.0,
            ytdEpfK: 2200.0,
            currentGrossY1: 4000.0,
            currentEpfK1: 440.0,
            monthsRemainingAfterCurrent: 6,
            ytdMtdPaidX: 177.65,
            currentAdditionalGrossYt: 5000.0,
            currentAdditionalEpfKt: 550.0,
        ));

        $this->assertSame(226.66, (new PcbCalculator)->calculate(new PcbInputs(
            category: 1,
            ytdGrossY: 20000.0,
            ytdEpfK: 2200.0,
            currentGrossY1: 4000.0,
            currentEpfK1: 440.0,
            monthsRemainingAfterCurrent: 6,
            ytdMtdPaidX: 177.65,
        ))->k2);
        $this->assertSame(60.35, $result->normalMtd);
        $this->assertSame(135.00, $result->k2);
        $this->assertSame(40000.00, $result->chargeableIncomeP);
        $this->assertSame(299.90, $result->additionalMtd);
        $this->assertSame(360.25, $result->totalPayable);
    }

    /** The five hand-worked months above, each reproduced by the calculator itself. */
    public function test_the_five_months_leading_up_to_the_bonus(): void
    {
        $pcb = new PcbCalculator;
        $months = [
            // [n, ∑Y, ∑K, X, expected MTD]
            [11, 0.0, 0.0, 0.0, 50.00],
            [10, 4000.0, 440.0, 50.00, 13.65],
            [9, 8000.0, 880.0, 63.65, 53.65],
            [8, 12000.0, 1320.0, 117.30, 0.00],
            [7, 16000.0, 1760.0, 117.30, 60.35],
        ];

        foreach ($months as [$n, $y, $k, $x, $expected]) {
            $result = $pcb->calculate(new PcbInputs(
                category: 1,
                ytdGrossY: $y,
                ytdEpfK: $k,
                currentGrossY1: 4000.0,
                currentEpfK1: 440.0,
                monthsRemainingAfterCurrent: $n,
                ytdMtdPaidX: $x,
            ));
            $this->assertSame($expected, $result->normalMtd, 'month with n='.$n);
        }
    }
}
