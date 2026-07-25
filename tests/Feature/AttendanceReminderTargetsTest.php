<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ReminderTargets;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for who deserves a clock nudge. Reference day is Thursday 2026-07-23,
 * office hours 09:00-18:00, 30-minute grace.
 */
class AttendanceReminderTargetsTest extends TestCase
{
    use RefreshDatabase;

    private const GRACE = 30;

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

    private function targets(): ReminderTargets
    {
        return app(ReminderTargets::class);
    }

    public function test_flags_staff_who_have_not_clocked_in_after_the_grace_window(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 09:31:00');

        $due = $this->targets()->missingClockIn($now, self::GRACE);

        $this->assertSame([$employee->id], $due->pluck('id')->all());
    }

    public function test_stays_quiet_inside_the_grace_window(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 09:29:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_stays_quiet_once_the_working_day_has_ended(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 18:05:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_who_already_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '08:55:00',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_on_approved_leave(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-24',
            'days' => 3,
            'status' => 'approved',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_public_holidays(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        PublicHoliday::create(['name' => 'Awal Muharram', 'date' => '2026-07-23']);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_weekends(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-25 09:31:00'); // Saturday

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_with_no_login_account(): void
    {
        Employee::create([
            'user_id' => null,
            'name' => 'Unprovisioned',
            'email' => 'nobody@acme.test',
            'branch_id' => $this->branch->id,
            'work_arrangement' => 'office',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_flags_an_open_record_past_its_expected_end(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:31:00');

        $open = $this->targets()->missingClockOut($now, self::GRACE);

        $this->assertSame([$record->id], $open->pluck('id')->all());
    }

    public function test_ignores_a_record_that_is_already_clocked_out(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'clock_out' => '18:02:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:31:00');

        $this->assertTrue($this->targets()->missingClockOut($now, self::GRACE)->isEmpty());
    }

    public function test_ignores_an_open_record_still_inside_the_grace_window(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:29:00');

        $this->assertTrue($this->targets()->missingClockOut($now, self::GRACE)->isEmpty());
    }
}
