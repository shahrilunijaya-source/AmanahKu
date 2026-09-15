<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Support\WorkWeek;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use App\Timesheet\DayRules;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The work-day hardcodes read the tenant now. Week under test: Mon 2026-07-27 to Sun
 * 2026-08-02; Sat 2026-08-01 is the first Saturday of August (Unijaya's TOT day). The
 * second week, Mon 2026-08-03 to Sun 2026-08-09, is used for the zero-capacity case.
 */
class WorkWeekBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $staff;

    private TimesheetCategory $work;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $tenantAttrs */
    private function company(array $tenantAttrs): void
    {
        $this->tenant = Tenant::create(array_merge(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'], $tenantAttrs));
        app(CurrentTenant::class)->set($this->tenant);
        $this->work = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_without_tot_the_first_saturday_is_a_day_off_for_leave_and_timesheets(): void
    {
        $this->company(['tot_saturday' => false]);

        $this->assertSame(0.0, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        $this->assertSame(0, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertSame(
            ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31'],
            (new DayRules)->weekWorkingDays('2026-07-27'),
        );
        $this->assertFalse((new DayRules)->isWorkingDay(Carbon::parse('2026-08-01')));

        // A holiday on that Saturday locks nothing: the week never asked for it.
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti Peristiwa', 'date' => '2026-08-01']);
        $this->assertSame([], app(LockedDays::class)->forWeek($this->staff, '2026-07-27'));
    }

    public function test_with_tot_the_first_saturday_is_a_half_day(): void
    {
        $this->company(['tot_saturday' => true]);

        $this->assertSame(0.5, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        $this->assertSame(50, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertEqualsWithDelta(50.0, DayCapacity::for('2026-08-01'), 0.001);
        $this->assertContains('2026-08-01', (new DayRules)->weekWorkingDays('2026-07-27'));
    }

    public function test_saturday_in_work_days_is_a_full_day_even_with_tot_on(): void
    {
        $this->company(['work_days' => [1, 2, 3, 4, 5, 6], 'tot_saturday' => true]);

        $this->assertSame(100, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertEqualsWithDelta(100.0, DayCapacity::for('2026-08-01'), 0.001);
        $this->assertSame(1.0, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        // Every Saturday, not just the first.
        $this->assertSame(1.0, LeaveRequest::countDays(Carbon::parse('2026-08-08'), Carbon::parse('2026-08-08')));
        $this->assertSame(
            ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01'],
            (new DayRules)->weekWorkingDays('2026-07-27'),
        );
    }

    public function test_a_sunday_working_company_reaches_sunday_in_the_week(): void
    {
        $this->company(['work_days' => [6, 7]]);

        $this->assertSame(['2026-08-01', '2026-08-02'], (new DayRules)->weekWorkingDays('2026-07-27'));

        // A holiday on the Sunday locks that Sunday (the week runs to day 7 now).
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti', 'date' => '2026-08-02']);
        $locked = app(LockedDays::class)->forWeek($this->staff, '2026-07-27');
        $this->assertSame(['2026-08-02'], array_keys($locked));
        $this->assertEqualsWithDelta(100.0, $locked['2026-08-02']['percentage'], 0.001);
    }

    public function test_a_week_with_nothing_to_fill_submits_without_an_error(): void
    {
        $this->company(['work_days' => [6, 7]]);
        Carbon::setTestNow('2026-08-10 09:00:00');
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti 1', 'date' => '2026-08-08']);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti 2', 'date' => '2026-08-09']);

        $this->postJson('/app/timesheets', [
            'week_start' => '2026-08-03',
            'entries' => [],
            'submit_now' => 1,
        ])->assertSuccessful();
    }

    public function test_week_ends_on_the_last_working_day(): void
    {
        $this->company(['work_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->assertSame('2026-08-02', Timesheet::computeWeekEndsOn(Carbon::parse('2026-07-27'))->toDateString());

        app(CurrentTenant::class)->set(Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT', 'tot_saturday' => true]));
        $this->assertSame('2026-08-01', Timesheet::computeWeekEndsOn(Carbon::parse('2026-07-27'))->toDateString());
        $this->assertSame('2026-08-07', Timesheet::computeWeekEndsOn(Carbon::parse('2026-08-03'))->toDateString());
    }
}
