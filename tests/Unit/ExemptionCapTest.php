<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\ExemptionCap;
use PHPUnit\Framework\TestCase;

class ExemptionCapTest extends TestCase
{
    public function test_five_hundred_a_month_stays_exempt_all_year_under_a_six_thousand_cap(): void
    {
        $used = 0.0;
        for ($month = 1; $month <= 12; $month++) {
            $this->assertSame(0.0, ExemptionCap::taxableThisMonth(500, $used, 6000));
            $used += 500;
        }
    }

    public function test_once_the_cap_is_used_the_whole_amount_is_taxable(): void
    {
        $this->assertSame(500.0, ExemptionCap::taxableThisMonth(500, 6000, 6000));
        $this->assertSame(500.0, ExemptionCap::taxableThisMonth(500, 9000, 6000));
    }

    public function test_the_month_that_crosses_the_cap_is_split(): void
    {
        $this->assertSame(300.0, ExemptionCap::taxableThisMonth(500, 5800, 6000));
    }

    public function test_no_cap_means_fully_taxable(): void
    {
        $this->assertSame(500.0, ExemptionCap::taxableThisMonth(500, 0, null));
        $this->assertSame(500.0, ExemptionCap::taxableThisMonth(500, 0, 0));
    }
}
