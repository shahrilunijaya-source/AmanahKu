<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\StatutoryBrackets;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    private PayrollCalculator $calc;

    /** Current published MY defaults — mirrors StatutoryRate::defaults(). */
    private array $rates = [
        'epf' => ['employee_pct' => 11, 'employer_pct_below' => 13, 'employer_pct_above' => 12, 'threshold' => 5000],
        'socso' => ['employer_pct' => 1.75, 'employee_pct' => 0.5, 'wage_ceiling' => 6000],
        'eis' => ['employer_pct' => 0.2, 'employee_pct' => 0.2, 'wage_ceiling' => 6000],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PayrollCalculator();
    }

    public function test_epf_employer_rate_steps_down_above_threshold(): void
    {
        // Wage above RM5,000 → employer 12%.
        $high = $this->calc->compute(['basic' => 11000, 'allowances_total' => 550], $this->rates);
        $this->assertSame(11550.0, $high->gross);
        $this->assertSame(1270.50, $high->epfEmployee);   // 11%
        $this->assertSame(1386.00, $high->epfEmployer);   // 12% (> threshold)

        // Wage at/below RM5,000 → employer 13%.
        $low = $this->calc->compute(['basic' => 4000], $this->rates);
        $this->assertSame(440.00, $low->epfEmployee);     // 11%
        $this->assertSame(520.00, $low->epfEmployer);     // 13% (<= threshold)
    }

    public function test_socso_and_eis_are_capped_at_wage_ceiling(): void
    {
        $c = $this->calc->compute(['basic' => 8000], $this->rates);
        // Base capped at RM6,000.
        $this->assertSame(30.00, $c->socsoEmployee);      // 0.5% of 6000
        $this->assertSame(105.00, $c->socsoEmployer);     // 1.75% of 6000
        $this->assertSame(12.00, $c->eisEmployee);        // 0.2% of 6000
        $this->assertSame(12.00, $c->eisEmployer);
    }

    public function test_overtime_uses_ordinary_rate_of_pay(): void
    {
        // hourly = 5200 / 26 / 8 = 25.00 ; OT = 10 * 25 * 1.5 = 375.00
        $c = $this->calc->compute(['basic' => 5200, 'overtime_hours' => 10], $this->rates);
        $this->assertSame(375.00, $c->overtimeAmount);
        $this->assertSame(5575.00, $c->gross);
    }

    public function test_unpaid_leave_prorates_against_daily_rate(): void
    {
        // daily = 5200 / 26 = 200 ; 2 days unpaid = 400 off gross
        $c = $this->calc->compute(['basic' => 5200, 'unpaid_days' => 2], $this->rates);
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
        ], $this->rates);

        $this->assertSame(3200.00, $c->gross);              // basic + one valid addition
        $this->assertSame(200.00, $c->additionsTotal);
        $this->assertCount(1, $c->additions);               // blanks stripped
        $this->assertSame(100.00, $c->otherDeductionsTotal);
        $this->assertSame(50.00, $c->pcb);
        // EPF 11% of 3200 = 352 ; SOCSO 16 ; EIS 6.40 ; + pcb 50 + other 100
        $this->assertSame(524.40, $c->totalDeductions);
        // net = gross - deductions + reimbursement
        $this->assertSame(2825.60, $c->netPay);
    }

    public function test_employer_cost_includes_employer_statutory(): void
    {
        $c = $this->calc->compute(['basic' => 4000], $this->rates);
        // 4000 + EPF er 520 + SOCSO er 70 + EIS er 8 = 4598
        $this->assertSame(4598.00, $c->employerCost);
        $this->assertSame(598.00, $c->statutoryEmployer());
    }

    public function test_zero_basic_produces_zeroed_payslip_without_errors(): void
    {
        $c = $this->calc->compute(['basic' => 0], $this->rates);
        $this->assertSame(0.0, $c->gross);
        $this->assertSame(0.0, $c->epfEmployee);
        $this->assertSame(0.0, $c->netPay);
        $this->assertSame(0.0, $c->employerCost);
    }

    public function test_unpaid_leave_cannot_push_gross_below_zero(): void
    {
        // 40 unpaid days against a 5200 basic would be negative raw earnings → clamps to 0.
        $c = $this->calc->compute(['basic' => 5200, 'unpaid_days' => 40], $this->rates);
        $this->assertSame(0.0, $c->gross);
        $this->assertSame(0.0, $c->epfEmployee);
        $this->assertSame(0.0, $c->netPay);
    }

    public function test_negative_inputs_are_clamped_to_zero(): void
    {
        $c = $this->calc->compute(['basic' => -500, 'bonus' => -100, 'overtime_hours' => -5], $this->rates);
        $this->assertSame(0.0, $c->basic);
        $this->assertSame(0.0, $c->bonus);
        $this->assertSame(0.0, $c->overtimeAmount);
        $this->assertSame(0.0, $c->gross);
    }

    // ── Bracket mode (PERKESO stepped schedule) ───────────────────

    /** Same rates as $rates but with SOCSO/EIS in bracket mode. */
    private array $bracketRates = [
        'epf' => ['employee_pct' => 11, 'employer_pct_below' => 13, 'employer_pct_above' => 12, 'threshold' => 5000],
        'socso' => ['employer_pct' => 1.75, 'employee_pct' => 0.5, 'wage_ceiling' => 6000, 'use_brackets' => true],
        'eis' => ['employer_pct' => 0.2, 'employee_pct' => 0.2, 'wage_ceiling' => 6000, 'use_brackets' => true],
    ];

    public function test_two_wages_in_the_same_band_pay_identical_socso_and_eis(): void
    {
        // 2910 and 2990 fall in the same RM2,900–3,000 band → identical contributions.
        $a = $this->calc->compute(['basic' => 2910], $this->bracketRates);
        $b = $this->calc->compute(['basic' => 2990], $this->bracketRates);

        $this->assertSame($a->socsoEmployee, $b->socsoEmployee);
        $this->assertSame($a->socsoEmployer, $b->socsoEmployer);
        $this->assertSame($a->eisEmployee, $b->eisEmployee);
        // But the flat-% path would differ for these two wages — confirm bracket ≠ flat here.
        $flat = $this->calc->compute(['basic' => 2990], $this->rates);
        $this->assertNotSame($flat->socsoEmployee, $b->socsoEmployee);
    }

    public function test_bracket_amounts_come_from_the_statutory_table(): void
    {
        $c = $this->calc->compute(['basic' => 3450], $this->bracketRates);
        $row = StatutoryBrackets::lookup(StatutoryBrackets::socso(1), 3450.0);
        $this->assertSame($row['ee'], $c->socsoEmployee);
        $this->assertSame($row['er'], $c->socsoEmployer);
    }

    public function test_above_ceiling_uses_the_top_band(): void
    {
        $atCeiling = $this->calc->compute(['basic' => 6000], $this->bracketRates);
        $above = $this->calc->compute(['basic' => 9000], $this->bracketRates);

        $this->assertSame($atCeiling->socsoEmployee, $above->socsoEmployee);
        $this->assertSame($atCeiling->eisEmployer, $above->eisEmployer);
    }

    public function test_category_two_zeroes_employee_socso_and_all_eis(): void
    {
        $c = $this->calc->compute(['basic' => 4000, 'statutory_category' => 2], $this->bracketRates);

        $this->assertSame(0.0, $c->socsoEmployee);          // ≥60: employee pays no SOCSO
        $this->assertGreaterThan(0.0, $c->socsoEmployer);   // employer still pays Employment Injury
        $this->assertSame(0.0, $c->eisEmployee);            // EIS does not apply at ≥60
        $this->assertSame(0.0, $c->eisEmployer);
    }
}
