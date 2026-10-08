<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ClockService;
use App\Attendance\LedgerBuilder;
use App\Attendance\ReminderTargets;
use App\Attendance\ScheduleResolver;
use App\Attendance\SiteSpec;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ManagementExceptions;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Freelancers keep their own hours: an employment type marked clock_exempt is never
 * reminded to clock, never late or absent, and a punch they choose to make carries no
 * shift to be judged early or short against. Reference day Thursday 2026-07-23, 09:00-18:00.
 */
class ClockExemptEmploymentTypeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->branch = Branch::create([
            'name' => 'HQ', 'latitude' => 3.10, 'longitude' => 101.60, 'radius_m' => 200,
            'work_start' => '09:00:00', 'work_end' => '18:00:00', 'min_hours' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, ?EmploymentType $type = null, string $role = 'employee'): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "staff{$this->seq}@acme.test", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'user_id' => $user->id, 'name' => $name, 'email' => "staff{$this->seq}@acme.test",
            'branch_id' => $this->branch->id, 'work_arrangement' => 'office',
            'status' => 'active', 'workload' => 'green', 'employment_type_id' => $type?->id,
        ]);
    }

    private function freelance(): EmploymentType
    {
        return EmploymentType::create(['name' => 'Freelance', 'clock_exempt' => true]);
    }

    public function test_hr_can_turn_clock_exempt_on_and_off_for_an_employment_type(): void
    {
        $hr = $this->staff('Hidayah', role: 'hr');
        $this->actingAs($hr->user)->withSession(['current_tenant' => $this->tenant->id]);

        $this->post(route('admin.employment-types.store'), ['name' => 'Freelance', 'clock_exempt' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $type = EmploymentType::where('name', 'Freelance')->firstOrFail();
        $this->assertTrue($type->clock_exempt);

        // An unticked checkbox posts nothing at all, and that has to switch it off.
        $this->post(route('admin.employment-types.update', $type), ['name' => 'Freelance'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($type->refresh()->clock_exempt);
    }

    public function test_clock_exempt_staff_get_no_clock_in_reminders(): void
    {
        $regular = $this->staff('Aina');
        $this->staff('Faris', $this->freelance());
        $targets = app(ReminderTargets::class);

        $this->assertSame([$regular->id], $targets->missingClockIn(Carbon::parse('2026-07-23 09:31:00'), 30)->pluck('id')->all());
        $this->assertSame([$regular->id], $targets->dueToClockIn(Carbon::parse('2026-07-23 08:50:00'), 15)->pluck('id')->all());
    }

    public function test_clock_exempt_staff_are_left_off_the_lateness_panel(): void
    {
        Carbon::setTestNow('2026-07-23 10:00:00');
        $regular = $this->staff('Aina');
        $this->staff('Faris', $this->freelance());

        $this->assertSame([$regular->id], array_column(app(ManagementExceptions::class)->lateness(null), 'employee_id'));
    }

    public function test_a_late_punch_by_clock_exempt_staff_is_on_time_and_carries_no_shift(): void
    {
        $freelancer = $this->staff('Faris', $this->freelance());
        $site = new SiteSpec('office', 'HQ', 3.10, 101.60, 200, '09:00', '18:00', 8.0);
        $clock = new ClockService(new class($site) extends ScheduleResolver
        {
            public function __construct(private SiteSpec $site) {}

            public function resolve(Employee $employee, CarbonInterface $date): SiteSpec
            {
                return $this->site;
            }
        });

        // 11:00 with no reason typed: regular staff would be asked to justify being late.
        $in = $clock->clockIn($freelancer, 3.1001, 101.6001, null, 'attendance-photos/a.jpg', Carbon::parse('2026-07-23 11:00:00'));
        $this->assertSame('ok', $in['status']);

        $record = $freelancer->attendanceRecords()->firstOrFail();
        $this->assertSame('on_time', $record->status);
        $this->assertNull($record->expected_end);
        $this->assertNull($record->expected_min_hours);

        // Two hours later, no reason: not early, not short, and nothing for the sweep to close.
        $out = $clock->clockOut($freelancer, 3.1001, 101.6001, null, 'attendance-photos/b.jpg', Carbon::parse('2026-07-23 13:00:00'));
        $this->assertSame('ok', $out['status']);
        $this->assertSame([], $record->refresh()->flags ?? []);
    }

    public function test_the_ledger_shows_clock_exempt_staff_only_on_days_they_punched(): void
    {
        $regular = $this->staff('Aina');
        $freelancer = $this->staff('Faris', $this->freelance());
        AttendanceRecord::create([
            'employee_id' => $freelancer->id, 'date' => '2026-07-21',
            'clock_in' => '14:00:00', 'clock_out' => '16:00:00', 'worked_minutes' => 120, 'status' => 'on_time',
        ]);

        $rows = app(LedgerBuilder::class)->build(
            Employee::active()->with('employmentType')->get(),
            AttendanceRecord::all(),
            collect(),
            ['2026-07-20', '2026-07-21', '2026-07-22'],
            CarbonImmutable::parse('2026-07-23'),
        );

        $this->assertSame(['absent', 'absent', 'absent'], $rows->where('employeeId', $regular->id)->pluck('status')->values()->all());
        // A two-hour day is a normal day for a freelancer, not a half day.
        $this->assertSame([['2026-07-21', 'ontime']], $rows->where('employeeId', $freelancer->id)->map(fn ($r) => [$r['date'], $r['status']])->values()->all());
    }
}
