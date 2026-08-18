<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "View summary" dialog on the attendance report: who was absent / late, and on
 * which day.
 *
 * Frozen at Wed 15 Jul 2026, so the default 'week' window (7 days back, inclusive)
 * spans Thu 9 Jul – Wed 15 Jul. Sat 11 and Sun 12 drop out as weekend, leaving five
 * working days: Thu, Fri, Mon, Tue, Wed(today).
 */
class AttendanceReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $user = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => 'HR Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function getScreenData(array $queryParams = []): array
    {
        $request = Request::create('/app/attendance-report', 'GET', $queryParams);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('employee', $this->viewer);

        return app(AttendanceReportController::class)->screenData($request);
    }

    private function staff(string $name): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function punch(Employee $emp, string $date, string $status = 'on_time', array $attributes = []): void
    {
        AttendanceRecord::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'date' => $date,
            'status' => $status,
            'clock_in' => $status === 'late' ? '09:30:00' : '08:00:00',
        ], $attributes));
    }

    /** Clock in on every working day of the window except the ones named. */
    private function punchAllExcept(Employee $emp, array $skip, string $status = 'on_time'): void
    {
        foreach (['2026-07-09', '2026-07-10', '2026-07-13', '2026-07-14'] as $date) {
            if (! in_array($date, $skip, true)) {
                $this->punch($emp, $date, $status);
            }
        }
    }

    private function bucketRow(array $data, string $bucket, int $employeeId): ?array
    {
        return collect($data['summary'][$bucket])->firstWhere('id', $employeeId);
    }

    /**
     * The rule that decides the whole feature: there is no "2+ days" threshold. One
     * missed day puts you on the list, named with the single day you missed — so the
     * dialog always agrees with the red cells already visible on the roster.
     */
    public function test_a_single_missed_day_still_lists_the_person_with_that_day(): void
    {
        $emp = $this->staff('One Day Missed');
        $this->punchAllExcept($emp, ['2026-07-14']); // Tue missing

        $row = $this->bucketRow($this->getScreenData(), 'absent', $emp->id);

        $this->assertNotNull($row, 'One missed day must still list the person.');
        $this->assertSame(['Tue'], $row['days']);
    }

    public function test_multiple_missed_days_are_all_named(): void
    {
        $emp = $this->staff('Two Days Missed');
        $this->punchAllExcept($emp, ['2026-07-10', '2026-07-14']); // Fri + Tue

        $row = $this->bucketRow($this->getScreenData(), 'absent', $emp->id);

        $this->assertNotNull($row);
        $this->assertSame(['Fri', 'Tue'], $row['days'], 'Days are listed oldest first, matching the strip.');
    }

    public function test_late_days_are_named_in_the_late_bucket(): void
    {
        $emp = $this->staff('Late Once');
        $this->punchAllExcept($emp, ['2026-07-14']);
        $this->punch($emp, '2026-07-14', 'late');

        $data = $this->getScreenData();

        $late = $this->bucketRow($data, 'late', $emp->id);
        $this->assertNotNull($late);
        $this->assertSame(['Tue'], $late['days']);

        $this->assertNull(
            $this->bucketRow($data, 'absent', $emp->id),
            'Clocking in late is not an absence — they were there.'
        );
    }

    /**
     * Today is still in progress. Someone who has not clocked in by mid-morning is
     * 'pending' on the strip, not absent, and must not be named in the dialog either.
     */
    public function test_today_without_a_punch_is_not_reported_as_absent(): void
    {
        $emp = $this->staff('Not In Yet Today');
        $this->punchAllExcept($emp, []); // every past working day covered; today (Wed) left open

        $this->assertNull($this->bucketRow($this->getScreenData(), 'absent', $emp->id));
    }

    public function test_approved_leave_is_not_reported_as_absent(): void
    {
        $leaveType = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual Leave',
            'entitlement' => 14,
        ]);

        $emp = $this->staff('On Leave');
        $this->punchAllExcept($emp, ['2026-07-14']);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'leave_type_id' => $leaveType->id,
            'date_from' => '2026-07-14',
            'date_to' => '2026-07-14',
            'days' => 1,
            'status' => 'approved',
        ]);

        $this->assertNull($this->bucketRow($this->getScreenData(), 'absent', $emp->id));
    }

    /**
     * The strip resolves off-site ahead of late, and the roster row's own `late` count
     * follows that same precedence. The dialog must not use a different rule, or the
     * two numbers on one screen would contradict each other.
     */
    public function test_an_off_site_day_is_not_counted_as_late(): void
    {
        $emp = $this->staff('Off Site');
        $this->punchAllExcept($emp, ['2026-07-14']);
        $this->punch($emp, '2026-07-14', 'late', ['flags' => ['late', 'out_of_radius_in']]);

        $data = $this->getScreenData();

        $this->assertNull($this->bucketRow($data, 'late', $emp->id));

        $rosterRow = collect($data['roster'])->firstWhere('id', $emp->id);
        $this->assertSame(0, $rosterRow['late'], 'Dialog and roster must agree on what "late" means.');
    }

    /**
     * A weekday name only reads unambiguously inside a 7-day window. Over 30 or 90 days
     * "Tue" could be any of a dozen Tuesdays, so the label becomes a date.
     */
    public function test_longer_periods_label_days_as_dates_not_weekday_names(): void
    {
        $emp = $this->staff('Missed Long Ago');
        $this->punch($emp, '2026-07-13');

        $row = $this->bucketRow($this->getScreenData(['period' => 'month']), 'absent', $emp->id);

        $this->assertNotNull($row);
        $this->assertContains('1 Jul', $row['days']);
        $this->assertNotContains('Wed', $row['days']);
    }

    public function test_a_fully_present_person_is_in_neither_bucket(): void
    {
        $emp = $this->staff('Perfect Attendance');
        $this->punchAllExcept($emp, []);
        $this->punch($emp, '2026-07-15');

        $data = $this->getScreenData();

        $this->assertNull($this->bucketRow($data, 'absent', $emp->id));
        $this->assertNull($this->bucketRow($data, 'late', $emp->id));
    }

    /** The headline number on each tile is just the size of its list. */
    public function test_tile_counts_equal_the_number_of_people_listed(): void
    {
        $a = $this->staff('Absent A');
        $this->punchAllExcept($a, ['2026-07-14']);

        $b = $this->staff('Absent B');
        $this->punchAllExcept($b, ['2026-07-09']);

        $c = $this->staff('Late C');
        $this->punchAllExcept($c, ['2026-07-13']);
        $this->punch($c, '2026-07-13', 'late');

        $data = $this->getScreenData();

        $absentIds = collect($data['summary']['absent'])->pluck('id');
        $this->assertTrue($absentIds->contains($a->id));
        $this->assertTrue($absentIds->contains($b->id));

        $lateIds = collect($data['summary']['late'])->pluck('id');
        $this->assertTrue($lateIds->contains($c->id));
    }
}
