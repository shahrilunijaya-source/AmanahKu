<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\WorkDayRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkDayRuleTest extends TestCase
{
    private function rule(int $weekday, array $weeks): WorkDayRule
    {
        return new WorkDayRule(['weekday' => $weekday, 'weeks' => $weeks, 'start_time' => '09:00:00', 'end_time' => '13:00:00', 'counts' => 'half']);
    }

    public function test_first_saturday_matches_only_the_first(): void
    {
        $rule = $this->rule(6, [1]);

        $this->assertTrue($rule->matches(CarbonImmutable::parse('2026-10-03')));
        $this->assertFalse($rule->matches(CarbonImmutable::parse('2026-10-10')));
        $this->assertFalse($rule->matches(CarbonImmutable::parse('2026-10-31')));
    }

    public function test_second_and_fourth_saturday(): void
    {
        $rule = $this->rule(6, [2, 4]);

        $this->assertFalse($rule->matches(CarbonImmutable::parse('2026-10-03')));
        $this->assertTrue($rule->matches(CarbonImmutable::parse('2026-10-10')));
        $this->assertTrue($rule->matches(CarbonImmutable::parse('2026-10-24')));
    }

    public function test_last_saturday_including_a_fifth_week(): void
    {
        $rule = $this->rule(6, [-1]);

        $this->assertTrue($rule->matches(CarbonImmutable::parse('2026-10-31')));
        $this->assertFalse($rule->matches(CarbonImmutable::parse('2026-10-24')));
        // November 2026: Saturdays are 7, 14, 21, 28; the 28th is both 4th and last.
        $this->assertTrue($rule->matches(CarbonImmutable::parse('2026-11-28')));
    }

    public function test_fifth_week_is_not_matched_by_1_to_4(): void
    {
        $this->assertFalse($this->rule(6, [1, 2, 3, 4])->matches(CarbonImmutable::parse('2026-10-31')));
    }

    public function test_wrong_weekday_never_matches(): void
    {
        $this->assertFalse($this->rule(6, [1])->matches(CarbonImmutable::parse('2026-10-02')));
    }

    public function test_length_and_capacity(): void
    {
        $rule = $this->rule(6, [1]);
        $this->assertSame(4.0, $rule->lengthInHours());
        $this->assertSame(50, $rule->capacity());
        $rule->counts = 'full';
        $this->assertSame(100, $rule->capacity());
    }
}
