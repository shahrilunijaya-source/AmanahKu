<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for attendance:remind. Reference day is Thursday 2026-07-23, office hours
 * 09:00-18:00. The command must be safe to run every 15 minutes, so repeat runs on the
 * same day must not stack bells.
 */
class AttendanceReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->branch = Branch::create([
            'name' => 'HQ',
            'latitude' => 3.1390,
            'longitude' => 101.6869,
            'radius_m' => 200,
            'work_start' => '09:00:00',
            'work_end' => '18:00:00',
            'min_hours' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, string $email): Employee
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'branch_id' => $this->branch->id,
            'work_arrangement' => 'office',
        ]);
    }

    public function test_bells_a_staffer_who_has_not_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');

        $bell = AppNotification::where('user_id', $employee->user_id)->sole();
        $this->assertSame('Clock-in reminder', $bell->title);
        $this->assertSame('attendance-in-2026-07-23', $bell->dedupe_key);
    }

    public function test_bells_a_staffer_still_clocked_in_past_their_end(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        Carbon::setTestNow('2026-07-23 18:31:00');

        Artisan::call('attendance:remind');

        $bell = AppNotification::where('user_id', $employee->user_id)->sole();
        $this->assertSame('Clock-out reminder', $bell->title);
        $this->assertSame('attendance-out-2026-07-23', $bell->dedupe_key);
    }

    public function test_repeat_runs_on_the_same_day_do_not_stack_bells(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');
        Carbon::setTestNow('2026-07-23 09:46:00');
        Artisan::call('attendance:remind');
        Carbon::setTestNow('2026-07-23 10:01:00');
        Artisan::call('attendance:remind');

        $this->assertSame(1, AppNotification::where('user_id', $employee->user_id)->count());
    }

    public function test_sends_nothing_when_everyone_has_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '08:55:00',
            'expected_end' => '18:00:00',
        ]);
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_command_is_scheduled(): void
    {
        // withSchedule() in bootstrap/app.php registers its events through an
        // Artisan::starting hook, so the Schedule is only populated once a
        // console command has started. Booting schedule:list triggers that hook,
        // making this assertion independent of test order within the suite.
        Artisan::call('schedule:list');

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'attendance:remind'));

        $this->assertCount(1, $events);
    }
}
