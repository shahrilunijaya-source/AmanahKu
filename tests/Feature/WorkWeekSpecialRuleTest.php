<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\WorkDayRule;
use App\Support\WorkWeek;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkWeekSpecialRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function tenant(array $attrs = []): Tenant
    {
        return Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'] + $attrs)->fresh();
    }

    public function test_rule_on_an_unlisted_saturday_makes_it_a_half_working_day(): void
    {
        $tenant = $this->tenant();
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id]);
        $week = WorkWeek::for($tenant);

        $first = CarbonImmutable::parse('2026-10-03');
        $this->assertTrue($week->isWorkingDay($first));
        $this->assertTrue($week->isTotDay($first));
        $this->assertSame(50, $week->capacity($first));
        $this->assertSame(0.5, $week->dayFraction($first));
        $this->assertSame(0, $week->capacity(CarbonImmutable::parse('2026-10-10')));
    }

    /** Real data now has the legacy flag off, so leave must cost the half day through the rule. */
    public function test_leave_over_a_ruled_saturday_costs_half_a_day(): void
    {
        $tenant = $this->tenant(['tot_saturday' => false]);
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id]);
        app(CurrentTenant::class)->set($tenant);

        // Sat 3 Oct (1st Saturday) to Mon 5 Oct: 0.5 + Sunday off + 1.
        $this->assertSame(1.5, LeaveRequest::countDays(Carbon::parse('2026-10-03'), Carbon::parse('2026-10-05')));
        // Sat 10 Oct is not ruled.
        $this->assertSame(1.0, LeaveRequest::countDays(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-12')));
    }

    public function test_full_rule_is_a_full_day(): void
    {
        $tenant = $this->tenant();
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'counts' => 'full']);
        $week = WorkWeek::for($tenant);

        $this->assertSame(100, $week->capacity(CarbonImmutable::parse('2026-10-03')));
        $this->assertFalse($week->isHalfDay(CarbonImmutable::parse('2026-10-03')));
    }

    public function test_rule_beats_a_listed_weekday(): void
    {
        $tenant = $this->tenant(['work_days' => [1, 2, 3, 4, 5, 6]]);
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id]);
        $week = WorkWeek::for($tenant);

        $this->assertSame(50, $week->capacity(CarbonImmutable::parse('2026-10-03')));
        $this->assertSame(100, $week->capacity(CarbonImmutable::parse('2026-10-10')));
    }

    public function test_no_rule_leaves_behaviour_unchanged(): void
    {
        $tenant = $this->tenant();
        $week = WorkWeek::for($tenant);

        $this->assertSame(0, $week->capacity(CarbonImmutable::parse('2026-10-03')));
        $this->assertSame(100, $week->capacity(CarbonImmutable::parse('2026-10-02')));
    }

    public function test_legacy_flag_still_works_and_rules_are_per_tenant(): void
    {
        $tenant = $this->tenant(['tot_saturday' => true]);
        $other = Tenant::create(['slug' => 'b', 'name' => 'B', 'initials' => 'B']);
        WorkDayRule::factory()->create(['tenant_id' => $other->id, 'counts' => 'full']);

        $this->assertSame(50, WorkWeek::for($tenant)->capacity(CarbonImmutable::parse('2026-10-03')));
        $this->assertNull(WorkWeek::for($tenant)->specialRule(CarbonImmutable::parse('2026-10-03')));
    }

    public function test_unsaved_tenant_has_no_rules(): void
    {
        $this->assertNull(WorkWeek::for(new Tenant(['work_days' => [1]]))->specialRule(CarbonImmutable::parse('2026-10-03')));
    }

    /** Shahril's November: the usual 1st Saturday moves to the 2nd, for that month only. */
    public function test_a_one_month_rule_replaces_the_usual_rule_for_that_month_only(): void
    {
        $tenant = $this->tenant();
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'weeks' => [1]]);
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'weeks' => [2], 'month' => '2026-11', 'end_time' => '14:00']);
        $week = WorkWeek::for($tenant);

        // October keeps the usual 1st Saturday.
        $this->assertTrue($week->isWorkingDay(CarbonImmutable::parse('2026-10-03')));
        $this->assertFalse($week->isWorkingDay(CarbonImmutable::parse('2026-10-10')));
        // November: 2nd Saturday works, the usual 1st Saturday does not.
        $this->assertFalse($week->isWorkingDay(CarbonImmutable::parse('2026-11-07')));
        $this->assertTrue($week->isWorkingDay(CarbonImmutable::parse('2026-11-14')));
        $this->assertSame('14:00', $week->specialRule(CarbonImmutable::parse('2026-11-14'))?->endHhmm());
        // December is back to normal.
        $this->assertTrue($week->isWorkingDay(CarbonImmutable::parse('2026-12-05')));
        $this->assertFalse($week->isWorkingDay(CarbonImmutable::parse('2026-12-12')));
    }

    public function test_a_one_month_rule_only_overrides_its_own_weekday(): void
    {
        $tenant = $this->tenant();
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'weeks' => [1]]);
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'weekday' => 7, 'weeks' => [2], 'month' => '2026-11']);
        $week = WorkWeek::for($tenant);

        $this->assertTrue($week->isWorkingDay(CarbonImmutable::parse('2026-11-07')));
        $this->assertTrue($week->isWorkingDay(CarbonImmutable::parse('2026-11-08')));
    }

    /** The leave form's day preview reads this map, so it must match countDays() day by day. */
    public function test_day_fractions_lists_what_each_working_day_costs(): void
    {
        $tenant = $this->tenant();
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id, 'weeks' => [2]]);
        PublicHoliday::create(['tenant_id' => $tenant->id, 'name' => 'Deepavali', 'date' => '2026-11-09']);
        app(CurrentTenant::class)->set($tenant);

        // Fri 6 .. Mon 16 Nov 2026: Sat 7 off, Sun 8 off, Mon 9 holiday, Sat 14 is the ruled half day.
        $this->assertSame(
            ['2026-11-06' => 1.0, '2026-11-10' => 1.0, '2026-11-11' => 1.0, '2026-11-12' => 1.0,
                '2026-11-13' => 1.0, '2026-11-14' => 0.5, '2026-11-16' => 1.0],
            LeaveRequest::dayFractions(Carbon::parse('2026-11-06'), Carbon::parse('2026-11-16')),
        );
        $this->assertSame(6.5, LeaveRequest::countDays(Carbon::parse('2026-11-06'), Carbon::parse('2026-11-16')));
    }
}
