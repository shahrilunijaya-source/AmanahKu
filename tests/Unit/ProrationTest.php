<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\Proration;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProrationTest extends TestCase
{
    public function test_joiner_in_a_29_day_february(): void
    {
        $d = Proration::days('2028-02', CarbonImmutable::parse('2028-02-08'), null);
        $this->assertSame(['employed' => 22, 'in_month' => 29], $d);
        $this->assertSame(2275.86, Proration::prorate(3000, 22, 29));
    }

    public function test_leaver_on_15_april(): void
    {
        $d = Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2028-04-15'));
        $this->assertSame(['employed' => 15, 'in_month' => 30], $d);
        $this->assertSame(1500.00, Proration::prorate(3000, 15, 30));
    }

    public function test_full_month_is_untouched(): void
    {
        $d = Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), null);
        $this->assertSame(['employed' => 30, 'in_month' => 30], $d);
        $this->assertSame(3000.00, Proration::prorate(3000, 30, 30));
    }

    public function test_left_before_the_month_gives_zero_days(): void
    {
        $this->assertSame(0, Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2028-03-31'))['employed']);
    }
}
