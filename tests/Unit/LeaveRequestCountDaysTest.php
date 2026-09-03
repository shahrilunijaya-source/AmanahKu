<?php

namespace Tests\Unit;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Leave is charged for working days only: Mon–Fri plus the TOT Saturday (first Saturday
 * of the month, a half day). Sundays and ordinary Saturdays are never working days.
 */
class LeaveRequestCountDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_tot_saturday_to_monday_skips_the_sunday(): void
    {
        // 5 Sep 2026 is the first Saturday of September; 6 Sep is Sunday.
        $this->assertSame(1.5, LeaveRequest::countDays(Carbon::parse('2026-09-05'), Carbon::parse('2026-09-07')));
    }

    public function test_ordinary_weekend_is_free(): void
    {
        // Fri 11 Sep – Mon 14 Sep: Sat 12 is not the TOT Saturday.
        $this->assertSame(2.0, LeaveRequest::countDays(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-14')));
        $this->assertSame(0.0, LeaveRequest::countDays(Carbon::parse('2026-09-13'), Carbon::parse('2026-09-13')));
    }

    public function test_full_week_is_five_days(): void
    {
        $this->assertSame(5.0, LeaveRequest::countDays(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20')));
    }
}
