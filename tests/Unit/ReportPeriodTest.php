<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Attendance\ReportPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        // A Thursday, mid-month, so month/week/day all differ.
        $this->today = CarbonImmutable::parse('2026-08-20');
    }

    public function test_it_defaults_to_the_calendar_month_to_date(): void
    {
        $p = ReportPeriod::fromRequest([], $this->today);

        $this->assertSame('month', $p->gran);
        $this->assertSame('2026-08-01', $p->from->toDateString());
        $this->assertSame('2026-08-20', $p->to->toDateString());
        $this->assertSame('August 2026', $p->label('en'));
        $this->assertSame('Ogos 2026', $p->label('ms'));
    }

    public function test_week_is_the_calendar_week_containing_today(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);

        $this->assertSame('2026-08-17', $p->from->toDateString()); // Monday
        $this->assertSame('2026-08-20', $p->to->toDateString());   // today, not Sunday
    }

    public function test_day_is_today(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'day'], $this->today);

        $this->assertSame('2026-08-20', $p->from->toDateString());
        $this->assertSame('2026-08-20', $p->to->toDateString());
        $this->assertSame('Thu, 20 Aug', $p->label('en'));
        $this->assertSame('Kha, 20 Ogos', $p->label('ms'));
    }

    public function test_an_offset_steps_backwards_and_clamps_forward(): void
    {
        $back = ReportPeriod::fromRequest(['gran' => 'week', 'offset' => '-1'], $this->today);
        $this->assertSame('2026-08-10', $back->from->toDateString());
        $this->assertSame('2026-08-14', $back->to->toDateString());
        $this->assertTrue($back->canNext);

        $now = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);
        $this->assertFalse($now->canNext, 'cannot step into the future');
    }

    public function test_a_custom_range_is_honoured(): void
    {
        $p = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-10', 'to' => '2026-08-12'],
            $this->today
        );

        $this->assertSame('custom', $p->gran);
        $this->assertSame('2026-08-10', $p->from->toDateString());
        $this->assertSame('2026-08-12', $p->to->toDateString());
        $this->assertFalse($p->canPrev);
        $this->assertFalse($p->canNext);
    }

    public function test_a_reversed_or_future_custom_range_falls_back_to_the_month(): void
    {
        $reversed = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-12', 'to' => '2026-08-10'],
            $this->today
        );
        $this->assertSame('month', $reversed->gran);

        $future = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'],
            $this->today
        );
        $this->assertSame('2026-08-20', $future->to->toDateString(), 'clamped to today');
        $this->assertTrue(
            $future->from->lte($future->to),
            'clamping must never leave the window inside out'
        );
    }

    public function test_garbage_input_falls_back_to_the_month(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => '../../etc/passwd'], $this->today);

        $this->assertSame('month', $p->gran);
    }

    public function test_working_days_are_weekdays_plus_any_date_carrying_a_record(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);

        $this->assertSame(
            ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'],
            $p->workingDays([])
        );
    }

    public function test_a_weekend_record_joins_the_working_days(): void
    {
        $p = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-14', 'to' => '2026-08-18'],
            $this->today
        );

        // 15th is a Saturday. Nobody normally works it; this one person did.
        $this->assertSame(
            ['2026-08-14', '2026-08-15', '2026-08-17', '2026-08-18'],
            $p->workingDays(['2026-08-15'])
        );
    }

    public function test_the_caption_names_the_period_it_totals(): void
    {
        $this->assertSame('month', ReportPeriod::fromRequest([], $this->today)->captionKey());
        $this->assertSame('week', ReportPeriod::fromRequest(['gran' => 'week'], $this->today)->captionKey());
        $this->assertSame('day', ReportPeriod::fromRequest(['gran' => 'day'], $this->today)->captionKey());
        $this->assertSame(
            'weekPast',
            ReportPeriod::fromRequest(['gran' => 'week', 'offset' => '-1'], $this->today)->captionKey(),
            'a past week is named, not called "this week"'
        );
    }
}
