<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\EisCalculator;
use App\Services\Payroll\EpfCalculator;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\SocsoCalculator;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private PayrollCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PayrollCalculator(new EpfCalculator, new SocsoCalculator, new EisCalculator);
    }

    public function test_epf_employer_rate_steps_down_above_threshold(): void
    {
        // Wage above RM5,000 → employer 12% of the RM100 band ceiling (11,550 → band 11,600).
        $high = $this->calc->compute(['basic' => 11000, 'allowances_total' => 550]);
        $this->assertSame(11550.0, $high->gross);
        $this->assertSame(1276.00, $high->epfEmployee);   // 11% of 11,600
        $this->assertSame(1392.00, $high->epfEmployer);   // 12% of 11,600 (> threshold)

        // Wage at/below RM5,000 → employer 13% (4,000 is exactly on a band boundary).
        $low = $this->calc->compute(['basic' => 4000]);
        $this->assertSame(440.00, $low->epfEmployee);     // 11%
        $this->assertSame(520.00, $low->epfEmployer);     // 13% (<= threshold)
    }

    public function test_epf_third_schedule_band_for_a_3000_wage(): void
    {
        // Third Schedule Part A, band 2,980.01–3,000.00 (verified against the fixture CSV).
        $c = $this->calc->compute(['basic' => 3000]);
        $this->assertSame(330.00, $c->epfEmployee);
        $this->assertSame(390.00, $c->epfEmployer);
    }

    public function test_epf_third_schedule_band_for_a_4010_wage(): void
    {
        // Third Schedule Part A, band 4,000.01–4,020.00: 11%/13% of the 4,020 band ceiling,
        // rounded up. The fixture gives 443/523 here — not 444/525.
        $c = $this->calc->compute(['basic' => 4010]);
        $this->assertSame(443.00, $c->epfEmployee);
        $this->assertSame(523.00, $c->epfEmployer);
    }

    public function test_socso_and_eis_are_capped_at_the_ceiling_row(): void
    {
        // Basic 8,000 is above the RM6,000 ceiling — uses the schedule's last row, same
        // as wages of exactly RM6,000.
        $atCeiling = $this->calc->compute(['basic' => 6000]);
        $above = $this->calc->compute(['basic' => 8000]);
        $this->assertSame($atCeiling->socsoEmployee, $above->socsoEmployee);
        $this->assertSame($atCeiling->socsoEmployer, $above->socsoEmployer);
        $this->assertSame($atCeiling->eisEmployee, $above->eisEmployee);
        $this->assertSame(29.75, $above->socsoEmployee);
        $this->assertSame(104.15, $above->socsoEmployer);
        $this->assertSame(11.90, $above->eisEmployee);
        $this->assertSame(11.90, $above->eisEmployer);
    }

    public function test_overtime_uses_ordinary_rate_of_pay(): void
    {
        // hourly = 5200 / 26 / 8 = 25.00 ; OT = 10 * 25 * 1.5 = 375.00
        $c = $this->calc->compute(['basic' => 5200, 'overtime_hours' => 10]);
        $this->assertSame(375.00, $c->overtimeAmount);
        $this->assertSame(5575.00, $c->gross);
    }

    /**
     * A pull mixing a 1.5x and a 3.0x request must produce two independent groups, each
     * multiplied by its own rate exactly once — never flattened into one "equivalent
     * hours" total that risks getting multiplied a second time.
     */
    public function test_overtime_groups_are_multiplied_independently_and_not_flattened(): void
    {
        // hourly = 5200/26/8 = 25.00 ; 6h*25*1.5 = 225.00 ; 4h*25*3.0 = 300.00
        $c = $this->calc->compute([
            'basic' => 5200,
            'overtime_groups' => [
                ['hours' => 6, 'multiplier' => 1.5],
                ['hours' => 4, 'multiplier' => 3.0],
            ],
        ]);

        $this->assertSame(10.0, $c->overtimeHours);
        $this->assertSame(525.00, $c->overtimeAmount);
        $this->assertNotEqualsWithDelta(787.50, $c->overtimeAmount, 0.001, 'must not treat 10h as one blended rate');
        $this->assertCount(2, $c->overtimeGroups);
        $this->assertSame(6.0, $c->overtimeGroups[0]['hours']);
        $this->assertSame(1.5, $c->overtimeGroups[0]['multiplier']);
        $this->assertSame(225.00, $c->overtimeGroups[0]['amount']);
        $this->assertSame(4.0, $c->overtimeGroups[1]['hours']);
        $this->assertSame(3.0, $c->overtimeGroups[1]['multiplier']);
        $this->assertSame(300.00, $c->overtimeGroups[1]['amount']);
    }

    /** An override of 12 raw hours at the default 1.5x pays exactly that — nothing else. */
    public function test_overtime_override_at_default_multiplier_pays_exactly_hours_times_1_5(): void
    {
        // 12 * 25.00 * 1.5 = 450.00
        $c = $this->calc->compute(['basic' => 5200, 'overtime_hours' => 12]);
        $this->assertSame(450.00, $c->overtimeAmount);
    }

    /** An override at an explicit 3.0x multiplier pays 3x, never the default 1.5x (nor a stacked 4.5x). */
    public function test_overtime_override_at_explicit_multiplier_pays_that_rate_not_the_default(): void
    {
        // 12 * 25.00 * 3.0 = 900.00
        $c = $this->calc->compute(['basic' => 5200, 'overtime_hours' => 12, 'overtime_multiplier' => 3.0]);
        $this->assertSame(900.00, $c->overtimeAmount);
        $this->assertNotEqualsWithDelta(450.00, $c->overtimeAmount, 0.001);
        $this->assertNotEqualsWithDelta(540.00, $c->overtimeAmount, 0.001, 'must not stack 3.0x on top of the 1.5x default');
    }

    /** s.2 EPF Act 1991: overtime is not "wages", so it must not raise the EPF contribution. */
    public function test_epf_ignores_overtime_but_socso_does_not(): void
    {
        $withoutOvertime = $this->calc->compute(['basic' => 5200]);
        $withOvertime = $this->calc->compute(['basic' => 5200, 'overtime_hours' => 10]);

        $this->assertSame(5575.00, $withOvertime->gross, 'Overtime still counts towards gross pay.');
        $this->assertSame($withoutOvertime->epfEmployee, $withOvertime->epfEmployee);
        $this->assertSame($withoutOvertime->epfEmployer, $withOvertime->epfEmployer);
        $this->assertSame(572.00, $withOvertime->epfEmployee);   // 11% of the RM5,200 band, not of RM5,575
        $this->assertSame(624.00, $withOvertime->epfEmployer);   // 12% of RM5,200 (above the RM5,000 step)

        // SOCSO/EIS wages DO include overtime (PERKESO's payments-subject-to-contribution
        // list), so those move with the higher wage.
        $this->assertGreaterThan($withoutOvertime->socsoEmployer, $withOvertime->socsoEmployer);
    }

    /** Bonus IS wages for EPF — it must raise the contribution, unlike overtime. */
    public function test_epf_includes_bonus(): void
    {
        $c = $this->calc->compute(['basic' => 3000, 'bonus' => 1000]);
        $this->assertSame(4000.00, $c->gross);
        $this->assertSame(440.00, $c->epfEmployee);   // 11% of RM4,000, not of RM3,000
        $this->assertSame(520.00, $c->epfEmployer);
    }

    /**
     * PERKESO's payments-subject-to-contribution list EXCLUDES the annual bonus, unlike
     * EPF wages (which exclude overtime instead). The two statutory wage bases are
     * deliberately different — this proves both directions.
     */
    public function test_socso_and_eis_exclude_bonus_but_epf_includes_it(): void
    {
        $withoutBonus = $this->calc->compute(['basic' => 3000]);
        $withBonus = $this->calc->compute(['basic' => 3000, 'bonus' => 1000]);

        $this->assertSame(4000.00, $withBonus->gross);
        $this->assertGreaterThan($withoutBonus->epfEmployee, $withBonus->epfEmployee);
        $this->assertSame($withoutBonus->socsoEmployee, $withBonus->socsoEmployee);
        $this->assertSame($withoutBonus->socsoEmployer, $withBonus->socsoEmployer);
        $this->assertSame($withoutBonus->eisEmployee, $withBonus->eisEmployee);
    }

    /** Overtime IS part of the SOCSO/EIS wage base, unlike EPF's (which excludes it). */
    public function test_socso_and_eis_include_overtime_but_epf_excludes_it(): void
    {
        $withoutOvertime = $this->calc->compute(['basic' => 3000]);
        $withOvertime = $this->calc->compute(['basic' => 3000, 'overtime_hours' => 10]);

        $this->assertSame($withoutOvertime->epfEmployee, $withOvertime->epfEmployee);
        $this->assertSame($withoutOvertime->epfEmployer, $withOvertime->epfEmployer);
        $this->assertGreaterThan($withoutOvertime->socsoEmployee, $withOvertime->socsoEmployee);
        $this->assertGreaterThan($withoutOvertime->eisEmployee, $withOvertime->eisEmployee);
    }

    public function test_unpaid_leave_prorates_against_daily_rate(): void
    {
        // daily = 5200 / 26 = 200 ; 2 days unpaid = 400 off gross
        $c = $this->calc->compute(['basic' => 5200, 'unpaid_days' => 2]);
        $this->assertSame(400.00, $c->unpaidDeduction);
        $this->assertSame(4800.00, $c->gross);
    }

    public function test_manual_pcb_and_reimbursement_flow_into_net(): void
    {
        $c = $this->calc->compute([
            'basic' => 3000,
            'additions' => [
                ['name' => 'Project bonus', 'amount' => 200],
                ['name' => '', 'amount' => 50],      // dropped: no name
                ['name' => 'Ghost', 'amount' => 0],  // dropped: zero amount
            ],
            'other_deductions' => [['name' => 'Salary advance', 'amount' => 100]],
            'pcb' => 50,
            'claims_reimbursement' => 150,
        ]);

        $this->assertSame(3200.00, $c->gross);              // basic + one valid addition
        $this->assertSame(200.00, $c->additionsTotal);
        $this->assertCount(1, $c->additions);               // blanks stripped
        $this->assertSame(100.00, $c->otherDeductionsTotal);
        $this->assertSame(50.00, $c->pcb);
        // EPF 11% of 3200 = 352 ; SOCSO 15.75 ; EIS 6.30 ; + pcb 50 + other 100
        $this->assertSame(524.05, $c->totalDeductions);
        // net = gross - deductions + reimbursement
        $this->assertSame(2825.95, $c->netPay);
    }

    public function test_employer_cost_includes_employer_statutory(): void
    {
        $c = $this->calc->compute(['basic' => 4000]);
        // 4000 + EPF er 520 + SOCSO er 69.15 + EIS er 7.90 = 4597.05
        $this->assertSame(4597.05, $c->employerCost);
        $this->assertSame(597.05, $c->statutoryEmployer());
    }

    public function test_zero_basic_produces_zeroed_payslip_without_errors(): void
    {
        $c = $this->calc->compute(['basic' => 0]);
        $this->assertSame(0.0, $c->gross);
        $this->assertSame(0.0, $c->epfEmployee);
        $this->assertSame(0.0, $c->socsoEmployee);
        $this->assertSame(0.0, $c->eisEmployee);
        $this->assertSame(0.0, $c->netPay);
        $this->assertSame(0.0, $c->employerCost);
    }

    public function test_unpaid_leave_cannot_push_gross_below_zero(): void
    {
        // 40 unpaid days against a 5200 basic would be negative raw earnings → clamps to 0.
        $c = $this->calc->compute(['basic' => 5200, 'unpaid_days' => 40]);
        $this->assertSame(0.0, $c->gross);
        $this->assertSame(0.0, $c->epfEmployee);
        $this->assertSame(0.0, $c->netPay);
    }

    public function test_negative_inputs_are_clamped_to_zero(): void
    {
        $c = $this->calc->compute(['basic' => -500, 'bonus' => -100, 'overtime_hours' => -5]);
        $this->assertSame(0.0, $c->basic);
        $this->assertSame(0.0, $c->bonus);
        $this->assertSame(0.0, $c->overtimeAmount);
        $this->assertSame(0.0, $c->gross);
    }

    public function test_two_wages_in_the_same_band_pay_identical_socso_and_eis(): void
    {
        // 2910 and 2990 fall in the same RM2,900–3,000 band → identical contributions.
        $a = $this->calc->compute(['basic' => 2910]);
        $b = $this->calc->compute(['basic' => 2990]);

        $this->assertSame($a->socsoEmployee, $b->socsoEmployee);
        $this->assertSame($a->socsoEmployer, $b->socsoEmployer);
        $this->assertSame($a->eisEmployee, $b->eisEmployee);
    }

    public function test_category_two_zeroes_employee_socso_and_all_eis(): void
    {
        $c = $this->calc->compute(['basic' => 4000, 'statutory_category' => 2]);

        $this->assertSame(0.0, $c->socsoEmployee);          // ≥60: employee pays no SOCSO
        $this->assertGreaterThan(0.0, $c->socsoEmployer);   // employer still pays Employment Injury
        $this->assertSame(0.0, $c->eisEmployee);            // EIS does not apply at ≥60
        $this->assertSame(0.0, $c->eisEmployer);
    }

    // ── SKBBK opt-in ────────────────────────────────────────────────

    public function test_skbbk_is_a_separate_line_not_folded_into_socso_employee(): void
    {
        $optedOut = $this->calc->compute(['basic' => 4000]);
        $optedIn = $this->calc->compute(['basic' => 4000, 'skbbk_opt_in' => true]);

        $this->assertSame(0.0, $optedOut->skbbkEmployee);
        $this->assertGreaterThan(0.0, $optedIn->skbbkEmployee);
        // SKBBK opt-in must not change the SOCSO-proper (Invalidity) figure.
        $this->assertSame($optedOut->socsoEmployee, $optedIn->socsoEmployee);
        $this->assertSame($optedOut->socsoEmployer, $optedIn->socsoEmployer);
        // But it does widen total deductions and shrink net pay by exactly the SKBBK amount.
        $this->assertSame(
            round($optedOut->totalDeductions + $optedIn->skbbkEmployee, 2),
            $optedIn->totalDeductions,
        );
        $this->assertSame(
            round($optedOut->netPay - $optedIn->skbbkEmployee, 2),
            $optedIn->netPay,
        );
    }

    public function test_skbbk_opt_in_works_for_category_two_with_no_other_socso(): void
    {
        $c = $this->calc->compute(['basic' => 4000, 'statutory_category' => 2, 'skbbk_opt_in' => true]);

        $this->assertSame(0.0, $c->socsoEmployee);          // still no Invalidity at ≥60
        $this->assertGreaterThan(0.0, $c->skbbkEmployee);   // but SKBBK applies if opted in
    }

    // ── Pay-item catalogue: flag-derived wage bases ──────────────────

    /**
     * lines/overtime_flags spell out, per pay item, exactly the same wage-base rule the
     * hardcoded gross-minus-overtime / gross-minus-bonus formulas encoded — so passing
     * them must reproduce identical EPF/SOCSO/EIS figures.
     */
    public function test_flag_derived_wage_bases_reproduce_the_legacy_hardcoded_rule(): void
    {
        $inputs = [
            'basic' => 5200,
            'allowances_total' => 300,
            'overtime_hours' => 10,
            'bonus' => 500,
        ];
        $legacy = $this->calc->compute($inputs);

        $flagged = $this->calc->compute($inputs + [
            'lines' => [
                ['amount' => 5200, 'epf_liable' => true, 'perkeso_liable' => true],   // basic
                ['amount' => 300, 'epf_liable' => true, 'perkeso_liable' => true],    // allowance
                ['amount' => 500, 'epf_liable' => true, 'perkeso_liable' => false],   // bonus: EPF yes, PERKESO no
            ],
            'overtime_flags' => ['epf_liable' => false, 'perkeso_liable' => true],    // overtime: EPF no, PERKESO yes
        ]);

        $this->assertSame($legacy->gross, $flagged->gross);
        $this->assertSame($legacy->epfEmployee, $flagged->epfEmployee);
        $this->assertSame($legacy->epfEmployer, $flagged->epfEmployer);
        $this->assertSame($legacy->socsoEmployee, $flagged->socsoEmployee);
        $this->assertSame($legacy->socsoEmployer, $flagged->socsoEmployer);
        $this->assertSame($legacy->eisEmployee, $flagged->eisEmployee);
    }

    /** An empty lines array is a deliberate "zero wage base", not "no catalogue data" — it must NOT fall back to the legacy rule. */
    public function test_empty_lines_array_is_not_treated_as_missing_catalogue_data(): void
    {
        $withLines = $this->calc->compute([
            'basic' => 5200,
            'lines' => [],
            'overtime_flags' => ['epf_liable' => false, 'perkeso_liable' => false],
        ]);

        $this->assertSame(0.0, $withLines->epfEmployee);
        $this->assertSame(0.0, $withLines->socsoEmployee);
    }
}
