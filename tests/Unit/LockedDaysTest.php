<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for LockedDays. The week under test is Mon 2026-07-20 to Fri 2026-07-24.
 */
class LockedDaysTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private LockedDays $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->svc = app(LockedDays::class);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_a_public_holiday_locks_that_weekday(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame(['2026-07-22'], array_keys($locked));
        $this->assertSame('holiday', $locked['2026-07-22']['source']);
        $this->assertSame('Awal Muharram', $locked['2026-07-22']['label']);
    }

    public function test_approved_leave_locks_every_weekday_it_covers(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-21', 'date_to' => '2026-07-22',
            'days' => 2, 'status' => 'approved',
        ]);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame(['2026-07-21', '2026-07-22'], array_keys($locked));
        $this->assertSame('leave', $locked['2026-07-21']['source']);
        $this->assertSame('Annual', $locked['2026-07-21']['label']);
    }

    public function test_unapproved_leave_locks_nothing(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-21', 'date_to' => '2026-07-21',
            'days' => 1, 'status' => 'submitted',
        ]);

        $this->assertSame([], $this->svc->forWeek($this->employee, '2026-07-20'));
    }

    public function test_a_public_holiday_outranks_leave_on_the_same_day(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
            'days' => 1, 'status' => 'approved',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame('holiday', $locked['2026-07-22']['source']);
    }

    public function test_weekend_days_are_never_locked(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Sunday Feast', 'date' => '2026-07-26']);

        $this->assertSame([], $this->svc->forWeek($this->employee, '2026-07-20'));
    }

    public function test_entry_rows_carry_the_matching_category_and_full_percentage(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $rows = $this->svc->entryRows($this->employee, '2026-07-20');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-22', $rows[0]['entry_date']);
        $this->assertSame(100.0, $rows[0]['percentage']);
        $this->assertSame('holiday', $rows[0]['source']);
        $this->assertSame(8.0, $rows[0]['hours']);
    }

    public function test_entry_rows_are_empty_when_the_tenant_deleted_the_category(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $this->assertSame([], $this->svc->entryRows($this->employee, '2026-07-20'));
    }

    public function test_for_week_many_keys_locked_days_by_employee(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-20', 'date_to' => '2026-07-20',
            'days' => 1, 'status' => 'approved',
        ]);

        $many = $this->svc->forWeekMany(collect([$this->employee, $other]), '2026-07-20');

        // The employee on leave has both the leave Monday and the shared holiday Wednesday.
        $this->assertSame(['2026-07-20', '2026-07-22'], array_keys($many[$this->employee->id]));
        // The other employee shares only the holiday.
        $this->assertSame(['2026-07-22'], array_keys($many[$other->id]));
    }

    public function test_for_week_many_matches_for_week_per_employee(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $many = $this->svc->forWeekMany(collect([$this->employee]), '2026-07-20');

        $this->assertEquals($this->svc->forWeek($this->employee, '2026-07-20'), $many[$this->employee->id]);
    }
}
