<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\PcbCalculator;
use PHPUnit\Framework\TestCase;

class PcbCalculatorTest extends TestCase
{
    private PcbCalculator $pcb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pcb = new PcbCalculator();
    }

    public function test_annual_tax_is_zero_below_the_first_band(): void
    {
        $this->assertSame(0.0, $this->pcb->annualTax(4000));
        $this->assertSame(0.0, $this->pcb->annualTax(5000));
    }

    public function test_annual_tax_is_progressive(): void
    {
        // 5,000@0 + 15,000@1% = 150
        $this->assertSame(150.0, $this->pcb->annualTax(20000));
        // + 15,000@3% (450) + 15,000@6% (900) = 1,500
        $this->assertSame(1500.0, $this->pcb->annualTax(50000));
    }

    public function test_standard_annual_relief_caps_epf(): void
    {
        // 550/mo EPF → 6,600/yr capped at 4,000, + 9,000 individual = 13,000
        $this->assertSame(13000.0, $this->pcb->standardAnnualRelief(550));
    }

    public function test_monthly_estimate_applies_relief_then_annualises(): void
    {
        // 5,000/mo → 60,000/yr − 13,000 relief = 47,000 chargeable.
        // tax = 150 + 450 + 12,000@6%(720) = 1,320 → /12 = 110.00
        $this->assertSame(110.0, $this->pcb->monthlyEstimate(5000, 13000));
    }

    public function test_low_chargeable_income_gets_the_rebate_to_zero(): void
    {
        // 2,000/mo → 24,000/yr − 13,000 = 11,000 chargeable → tax 60, rebate 400 → 0
        $this->assertSame(0.0, $this->pcb->monthlyEstimate(2000, 13000));
    }

    public function test_zero_income_produces_zero_pcb(): void
    {
        $this->assertSame(0.0, $this->pcb->monthlyEstimate(0, 13000));
    }
}
