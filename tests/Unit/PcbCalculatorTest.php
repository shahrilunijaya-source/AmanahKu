<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\PcbCalculator;
use App\Services\Payroll\PcbInputs;
use PHPUnit\Framework\TestCase;

class PcbCalculatorTest extends TestCase
{
    private PcbCalculator $pcb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pcb = new PcbCalculator;
    }

    // ── Exhibit 5: the acceptance test ───────────────────────────
    // Category 3, married with working spouse, 3 children, RM5,500/month, EPF RM605.
    // Year-to-date figures are fed forward exactly as the spec's own worked example does.

    public function test_exhibit5_january(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            currentGrossY1: 5500.0,
            currentEpfK1: 605.0,
            monthsRemainingAfterCurrent: 11,
            qualifyingChildren: 3,
        ));

        $this->assertSame(308.63, $result->k2);
        $this->assertSame(47000.07, $result->chargeableIncomeP);
        $this->assertSame(110.00, $result->normalMtd);
        $this->assertSame(110.00, $result->totalPayable);
    }

    public function test_exhibit5_february(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            ytdGrossY: 5500.0,
            ytdEpfK: 605.0,
            currentGrossY1: 5500.0,
            currentEpfK1: 605.0,
            monthsRemainingAfterCurrent: 10,
            ytdMtdPaidX: 110.0,
            qualifyingChildren: 3,
        ));

        $this->assertSame(279.00, $result->k2);
        $this->assertSame(47000.00, $result->chargeableIncomeP);
        $this->assertSame(110.00, $result->normalMtd);
    }

    public function test_exhibit5_march_with_optional_tp1_deductions(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            ytdGrossY: 11000.0,
            ytdEpfK: 1210.0,
            currentGrossY1: 5500.0,
            currentEpfK1: 605.0,
            monthsRemainingAfterCurrent: 9,
            ytdMtdPaidX: 220.0,
            qualifyingChildren: 3,
            currentOptionalDeductions: 300.0, // books RM100 + parental medical RM200
        ));

        $this->assertSame(242.77, $result->k2);
        $this->assertSame(46700.07, $result->chargeableIncomeP);
        $this->assertSame(108.20, $result->normalMtd);
    }

    public function test_exhibit5_april_normal_and_additional_remuneration(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            ytdGrossY: 16500.0,
            ytdEpfK: 1815.0,
            currentGrossY1: 5500.0,
            currentEpfK1: 605.0,
            monthsRemainingAfterCurrent: 8,
            ytdMtdPaidX: 328.20,
            qualifyingChildren: 3,
            ytdOptionalDeductions: 300.0, // March's TP1 claims
            currentOptionalDeductions: 300.0, // sport equipment RM100 + SSPN RM200
            currentAdditionalGrossYt: 8250.0, // bonus
            currentAdditionalEpfKt: 908.0,
        ));

        $this->assertSame(84.00, $result->k2);
        $this->assertSame(54650.00, $result->chargeableIncomeP);
        $this->assertSame(106.20, $result->normalMtd);
        $this->assertSame(106.20, $result->netNormalMtd);
        $this->assertSame(727.50, $result->additionalMtd);
        $this->assertSame(833.70, $result->totalPayable);
    }

    // ── Rounding (spec E.2): round up to nearest 5c, or 10c on a 6-9c tail ────

    public function test_mtd_ending_in_one_to_four_cents_rounds_up_to_five_cents(): void
    {
        // Constructed so the raw MTD is exactly RM287.02, the spec's own example.
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 109100.0,
            monthsRemainingAfterCurrent: 0,
            ytdMtdPaidX: 9137.98,
        ));

        $this->assertSame(287.05, $result->normalMtd);
    }

    public function test_mtd_ending_in_six_to_nine_cents_rounds_up_to_ten_cents(): void
    {
        // Raw MTD RM152.06, the spec's own example.
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 109100.0,
            monthsRemainingAfterCurrent: 0,
            ytdMtdPaidX: 9272.94,
        ));

        $this->assertSame(152.10, $result->normalMtd);
    }

    // ── Under-RM10 rule (spec E.3/E.4), including the zakat exception ─────────

    public function test_mtd_under_ten_ringgit_is_not_deducted(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 109100.0,
            monthsRemainingAfterCurrent: 0,
            ytdMtdPaidX: 9417.00, // raw MTD RM8.00
            currentZakat: 5.00,
        ));

        $this->assertSame(0.0, $result->normalMtd);
        // Net MTD would go negative (0 - 5); clamped to 0, matching the spec's own table.
        $this->assertSame(0.0, $result->netNormalMtd);
    }

    public function test_mtd_at_or_above_ten_ringgit_is_deducted_even_if_zakat_drops_it_under_ten(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 109100.0,
            monthsRemainingAfterCurrent: 0,
            ytdMtdPaidX: 9410.00, // raw MTD RM15.00
            currentZakat: 8.00,
        ));

        $this->assertSame(15.00, $result->normalMtd);
        $this->assertSame(7.00, $result->netNormalMtd); // still deducted despite being < RM10
    }

    public function test_mtd_above_zakat_deducts_the_net_amount(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 109100.0,
            monthsRemainingAfterCurrent: 0,
            ytdMtdPaidX: 9305.00, // raw MTD RM120.00
            currentZakat: 100.00,
        ));

        $this->assertSame(120.00, $result->normalMtd);
        $this->assertSame(20.00, $result->netNormalMtd);
    }

    public function test_chargeable_income_at_or_below_five_thousand_owes_no_mtd(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 4000.0,
            monthsRemainingAfterCurrent: 0,
        ));

        $this->assertSame(0.0, $result->normalMtd);
    }

    // ── Reliefs per category (spec D.b.1 (i)-(iii), E.14.i) ────────────────────

    public function test_category1_single_gets_only_the_individual_relief(): void
    {
        // n=0, no EPF: P = Y1 - reliefs. Category 1: D=9000 only, S=0, C forced to 0
        // even though qualifyingChildren is supplied (spec's own category definition).
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 50000.0,
            monthsRemainingAfterCurrent: 0,
            qualifyingChildren: 5,
        ));

        $this->assertSame(41000.00, $result->chargeableIncomeP); // 50000 - 9000
    }

    public function test_category2_gets_individual_spouse_and_child_reliefs(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 2,
            currentGrossY1: 50000.0,
            monthsRemainingAfterCurrent: 0,
            qualifyingChildren: 2,
        ));

        // 50000 - (9000 individual + 4000 spouse + 2000*2 children) = 33000
        $this->assertSame(33000.00, $result->chargeableIncomeP);
    }

    public function test_category3_gets_individual_and_child_reliefs_but_no_spouse_relief(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            currentGrossY1: 50000.0,
            monthsRemainingAfterCurrent: 0,
            qualifyingChildren: 2,
        ));

        // 50000 - (9000 individual + 2000*2 children), no spouse relief
        $this->assertSame(37000.00, $result->chargeableIncomeP);
    }

    public function test_disabled_individual_and_spouse_reliefs_stack(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 3,
            currentGrossY1: 50000.0,
            monthsRemainingAfterCurrent: 0,
            disabledIndividual: true,
            disabledSpouse: true,
        ));

        // 50000 - (9000 individual + 7000 disabled individual + 6000 disabled spouse)
        $this->assertSame(28000.00, $result->chargeableIncomeP);
    }

    // ── EPF relief cap (spec E.14.i.d, K2 formula) ─────────────────────────────

    public function test_k2_never_goes_negative_once_the_annual_epf_cap_is_used_up(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            ytdEpfK: 4000.0, // already at the annual cap
            currentGrossY1: 5000.0,
            currentEpfK1: 100.0,
            monthsRemainingAfterCurrent: 5,
        ));

        $this->assertSame(0.0, $result->k2);
    }

    public function test_k2_is_capped_at_k1_when_the_raw_estimate_is_higher(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            currentGrossY1: 5000.0,
            currentEpfK1: 50.0,
            monthsRemainingAfterCurrent: 1, // (4000-50)/1 = 3950, capped down to K1 = 50
        ));

        $this->assertSame(50.0, $result->k2);
    }

    // ── Non-resident (spec D.a) ─────────────────────────────────────────────────

    public function test_non_resident_pays_a_flat_thirty_percent_with_no_reliefs(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            isResident: false,
            currentGrossY1: 3000.0,
        ));

        $this->assertSame(900.00, $result->normalMtd);
    }

    public function test_non_resident_additional_remuneration_is_also_flat_thirty_percent(): void
    {
        $result = $this->pcb->calculate(new PcbInputs(
            category: 1,
            isResident: false,
            currentGrossY1: 3000.0,
            currentAdditionalGrossYt: 1000.0,
        ));

        $this->assertSame(900.00, $result->normalMtd);
        $this->assertSame(300.00, $result->additionalMtd);
        $this->assertSame(1200.00, $result->totalPayable);
    }
}
