<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LeaveRequest;
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
}
