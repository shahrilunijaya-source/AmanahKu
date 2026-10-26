<?php

namespace Tests\Unit;

use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Leave is charged for working days only. Unijaya-shaped tenant: Mon–Fri plus the TOT
 * Saturday (first Saturday of the month, a half day). Sundays and ordinary Saturdays are
 * never working days. The tot_saturday = false case lives in WorkWeekBehaviourTest.
 */
class LeaveRequestCountDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set(Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]));
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

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

    public function test_calendar_day_mode_counts_weekends_and_holidays(): void
    {
        PublicHoliday::create(['tenant_id' => app(CurrentTenant::class)->id(), 'name' => 'Malaysia Day', 'date' => '2026-09-16']);

        // Fri 11 Sep – Mon 14 Sep with the weekend: 4 calendar days, not 2 working days.
        $this->assertSame(4.0, LeaveRequest::countDays(Carbon::parse('2026-09-11'), Carbon::parse('2026-09-14'), true));
        // A holiday inside the range still costs a day.
        $this->assertSame(7.0, LeaveRequest::countDays(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), true));
        // Maternity: 98 consecutive days from 1 Oct ends on 6 Jan.
        $this->assertSame(98.0, LeaveRequest::countDays(Carbon::parse('2026-10-01'), Carbon::parse('2027-01-06'), true));
    }
}
