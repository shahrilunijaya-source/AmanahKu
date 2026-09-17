<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\WorkWeek;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-company working week. August 2026: Sat 1 Aug is the first Saturday of the
 * month (the TOT day when the flag is on), Sat 8 Aug an ordinary Saturday, Sun 2 Aug a
 * Sunday, Mon 3 Aug a Monday.
 */
class WorkWeekTest extends TestCase
{
    use RefreshDatabase;

    private const TOT_SATURDAY = '2026-08-01';

    private const PLAIN_SATURDAY = '2026-08-08';

    private const SUNDAY = '2026-08-02';

    private const MONDAY = '2026-08-03';

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function day(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso);
    }

    public function test_a_new_tenant_defaults_to_monday_to_friday_without_tot(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'])->fresh();

        $this->assertSame([1, 2, 3, 4, 5], $tenant->work_days);
        $this->assertFalse($tenant->tot_saturday);

        $week = WorkWeek::for($tenant);
        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays());
        $this->assertTrue($week->isWorkingDay($this->day(self::MONDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::SUNDAY)));
        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
    }

    public function test_a_six_day_week_works_every_saturday_at_full_capacity(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [1, 2, 3, 4, 5, 6]]);
        $week = WorkWeek::for($tenant);

        $this->assertSame([1, 2, 3, 4, 5, 6], $week->workingDays());
        $this->assertTrue($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertTrue($week->isWorkingDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::SUNDAY)));
        $this->assertSame(100, $week->capacity($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::SUNDAY)));
    }

    public function test_tot_on_makes_only_the_first_saturday_a_half_day(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertTrue($week->totSaturday());
        $this->assertTrue($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertTrue($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertFalse($week->isTotDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays(), 'TOT is a half day, never a listed work day');
    }

    public function test_tot_off_leaves_the_first_saturday_a_day_off(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => false]);
        $week = WorkWeek::for($tenant);

        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0.0, $week->dayFraction($this->day(self::TOT_SATURDAY)));
    }

    public function test_saturday_as_a_full_work_day_beats_the_tot_half_day(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [1, 2, 3, 4, 5, 6], 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertSame(100, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(1.0, $week->dayFraction($this->day(self::TOT_SATURDAY)));
    }

    public function test_capacity_and_fraction_values(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertSame(100, $week->capacity($this->day(self::MONDAY)));
        $this->assertSame(50, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::SUNDAY)));

        $this->assertSame(1.0, $week->dayFraction($this->day(self::MONDAY)));
        $this->assertSame(0.5, $week->dayFraction($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0.0, $week->dayFraction($this->day(self::PLAIN_SATURDAY)));
    }

    public function test_for_without_an_argument_reads_the_current_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [2, 3, 4, 5, 6]]);
        app(CurrentTenant::class)->set($tenant);

        $this->assertSame([2, 3, 4, 5, 6], WorkWeek::for()->workingDays());
        $this->assertFalse(WorkWeek::for()->isWorkingDay($this->day(self::MONDAY)));
    }

    public function test_for_without_a_bound_tenant_falls_back_to_monday_to_friday(): void
    {
        app(CurrentTenant::class)->set(null);

        $week = WorkWeek::for();

        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays());
        $this->assertFalse($week->totSaturday());
        $this->assertTrue($week->isWorkingDay($this->day(self::MONDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
    }

    public function test_working_days_are_sorted_deduplicated_integers(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => ['5', 1, 5, '3']]);

        $this->assertSame([1, 3, 5], WorkWeek::for($tenant)->workingDays());
    }
}
