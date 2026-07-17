<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the timesheet:remind command. Current week is Mon 2026-06-22.
 * A staffer with a fully-filled week gets no bell; an empty-week staffer does.
 */
class TimesheetReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-26 17:00:00'); // Friday 5pm
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, string $email): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);

        return [$user, $employee];
    }

    private function fillFullWeek(Employee $emp): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => '2026-06-22', 'status' => 'draft', 'total_hours' => 0,
        ]);
        foreach (['2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25', '2026-06-26'] as $d) {
            $ts->entries()->create([
                'tenant_id' => $this->tenant->id, 'entry_date' => $d,
                'category_id' => $cat->id, 'percentage' => 100, 'hours' => 8,
            ]);
        }
    }

    public function test_reminds_only_staff_whose_week_is_not_complete(): void
    {
        [$doneUser, $doneEmp] = $this->staff('Done Dan', 'dan@acme.test');
        [$pendingUser] = $this->staff('Pending Pat', 'pat@acme.test');
        $this->fillFullWeek($doneEmp);

        $this->artisan('timesheet:remind')->assertSuccessful();

        // Re-set context to read tenant-scoped notifications.
        app(CurrentTenant::class)->set($this->tenant);
        $this->assertTrue(
            AppNotification::where('user_id', $pendingUser->id)->where('title', 'Timesheet reminder')->exists()
        );
        $this->assertFalse(
            AppNotification::where('user_id', $doneUser->id)->exists()
        );
    }

    public function test_no_pending_staff_sends_no_notifications(): void
    {
        [$doneUser, $doneEmp] = $this->staff('Done Dan', 'dan@acme.test');
        $this->fillFullWeek($doneEmp);

        $this->artisan('timesheet:remind')->assertSuccessful();

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(0, AppNotification::count());
    }

    public function test_command_is_scheduled_for_friday_1700(): void
    {
        // withSchedule() in bootstrap/app.php registers its events through an
        // Artisan::starting hook, so the Schedule is only populated once a
        // console command has started. Booting schedule:list triggers that hook,
        // making this assertion independent of test order within the suite.
        Artisan::call('schedule:list');

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'timesheet:remind'));

        $this->assertTrue($events->isNotEmpty(), 'timesheet:remind is not scheduled');
        // Cron for Friday 17:00 = "0 17 * * 5".
        $this->assertSame('0 17 * * 5', $events->first()->expression);
    }

    public function test_overdue_banner_shows_after_friday_5pm_when_week_incomplete(): void
    {
        [$user] = $this->staff('Pending Pat', 'pat@acme.test'); // empty week
        Carbon::setTestNow('2026-06-26 17:30:00'); // Friday, past deadline

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertSee('Your timesheet for this week is overdue', false);
    }

    public function test_no_overdue_banner_before_the_deadline(): void
    {
        [$user] = $this->staff('Pending Pat', 'pat@acme.test'); // empty week
        Carbon::setTestNow('2026-06-24 09:00:00'); // Wednesday, before deadline

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertDontSee('Your timesheet for this week is overdue', false);
    }

    public function test_team_status_board_is_visible_to_a_plain_staffer(): void
    {
        [$me] = $this->staff('Me Myself', 'me@acme.test');
        [, $colleagueEmp] = $this->staff('Cathy Colleague', 'cathy@acme.test');
        $this->fillFullWeek($colleagueEmp); // Cathy is done

        $this->actingAs($me)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertSee('Cathy Colleague', false)   // roster lists everyone
            ->assertSee('team status', false);       // board heading (EN default text)
    }
}
