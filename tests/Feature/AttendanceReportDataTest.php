<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\AttendanceRecord;
use App\Models\Department;
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

class AttendanceReportDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'HR Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $this->leaveType = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual Leave',
            'entitlement' => 14,
        ]);
    }

    private function getScreenData(array $queryParams = [], string $scope = 'company'): array
    {
        $request = Request::create('/app/attendance-report', 'GET', $queryParams);
        $request->attributes->set('tenantScope', $scope);
        $request->attributes->set('employee', $this->employee);

        return app(AttendanceReportController::class)->screenData($request);
    }

    public function test_an_employee_with_no_records_still_gets_a_roster_row(): void
    {
        $noRecordEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Clock Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $data = $this->getScreenData();

        $roster = collect($data['roster']);
        $row = $roster->firstWhere('id', $noRecordEmp->id);

        $this->assertNotNull($row, 'Employee with no attendance records must appear in roster.');
        $this->assertTrue($row['never']);
        $this->assertFalse($row['stopped']);
        $this->assertFalse($row['onLeave']);
        $this->assertSame(0, $row['clocked']);
    }

    public function test_approved_leave_is_not_counted_as_absence(): void
    {
        $leaveEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $leaveEmp->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'days' => 2,
            'status' => 'approved',
        ]);

        $data = $this->getScreenData();

        $roster = collect($data['roster']);
        $row = $roster->firstWhere('id', $leaveEmp->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['onLeave']);
        $this->assertFalse($row['never']);
        $this->assertStringContainsString('v', $row['strip']);
    }

    public function test_pending_leave_does_not_mark_the_strip(): void
    {
        $pendingEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pending Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $pendingEmp->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'days' => 2,
            'status' => 'pending',
        ]);

        $data = $this->getScreenData();

        $roster = collect($data['roster']);
        $row = $roster->firstWhere('id', $pendingEmp->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['onLeave']);
        $this->assertStringNotContainsString('v', $row['strip']);
    }

    public function test_an_employee_who_stopped_clocking_is_flagged(): void
    {
        $stoppedEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stopped Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $stoppedEmp->id,
            'date' => '2026-07-08',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);

        $data = $this->getScreenData();

        $roster = collect($data['roster']);
        $row = $roster->firstWhere('id', $stoppedEmp->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['stopped']);
        $this->assertSame(5, $row['gapDays']);
        $this->assertSame('2026-07-08', $row['lastSeen']);
    }

    public function test_every_strip_matches_the_day_count(): void
    {
        $emp1 = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Emp One',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $emp2 = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Emp Two',
            'status' => 'active',
            'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp1->id,
            'date' => '2026-07-01',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp1->id,
            'date' => '2026-07-02',
            'status' => 'late',
            'clock_in' => '09:30:00',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp1->id,
            'date' => '2026-07-03',
            'status' => 'on_time',
            'clock_in' => '08:05:00',
        ]);

        $data = $this->getScreenData();
        $daysCount = count($data['days']);

        $this->assertGreaterThan(0, $daysCount);

        foreach ($data['roster'] as $row) {
            $this->assertSame($daysCount, strlen($row['strip']), "Strip length for {$row['name']} must match days count.");
        }
    }

    public function test_an_archived_employee_gets_no_row(): void
    {
        // Employee::scopeActive() keys off archived_at, not status. A fixture that
        // only sets status='archived' is not archived at all, and the assertion
        // below would then be testing nothing.
        $archivedEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Archived Staff',
            'status' => 'archived',
            'workload' => 'green',
            'archived_at' => now(),
        ]);

        $data = $this->getScreenData();

        $roster = collect($data['roster']);
        $this->assertNull($roster->firstWhere('id', $archivedEmp->id));
    }

    public function test_a_narrowed_data_scope_hides_other_staff(): void
    {
        $deptIn = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Engineering']);
        $deptOut = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);

        $this->employee->update(['department_id' => $deptIn->id]);

        $inScopeEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $deptIn->id,
            'name' => 'In Scope Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $outScopeEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'department_id' => $deptOut->id,
            'name' => 'Out Scope Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $data = $this->getScreenData([], 'department');

        $roster = collect($data['roster']);
        $this->assertNotNull($roster->firstWhere('id', $inScopeEmp->id));
        $this->assertNull($roster->firstWhere('id', $outScopeEmp->id));
    }

    public function test_a_weekend_record_is_added_to_the_days_list(): void
    {
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Weekend Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $dataBefore = $this->getScreenData();
        $countBefore = count($dataBefore['days']);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'date' => '2026-07-11',
            'status' => 'on_time',
            'clock_in' => '09:00:00',
        ]);

        $dataAfter = $this->getScreenData();
        $countAfter = count($dataAfter['days']);

        $this->assertContains('2026-07-11', $dataAfter['days']);
        $this->assertSame($countBefore + 1, $countAfter);

        foreach ($dataAfter['roster'] as $row) {
            $this->assertSame($countAfter, strlen($row['strip']));
        }
    }

    public function test_a_fully_absent_employee_on_leave_sorts_below_a_poor_attender(): void
    {
        $onLeaveEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Full Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $onLeaveEmp->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-07-31',
            'days' => 60,
            'status' => 'approved',
        ]);

        $poorAttender = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Poor Attender Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $poorAttender->id,
            'date' => '2026-07-14',
            'status' => 'late',
            'clock_in' => '10:00:00',
        ]);

        $data = $this->getScreenData();
        $roster = collect($data['roster']);

        $poorIndex = $roster->search(fn ($r) => $r['id'] === $poorAttender->id);
        $leaveIndex = $roster->search(fn ($r) => $r['id'] === $onLeaveEmp->id);

        $this->assertNotFalse($poorIndex);
        $this->assertNotFalse($leaveIndex);
        $this->assertLessThan($leaveIndex, $poorIndex, 'Poor attender must sort before a fully absent employee on leave.');
    }

    public function test_the_coverage_buckets_partition_the_roster(): void
    {
        // 1. Bucket clocking (without leave)
        $empClocking = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Clocking Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empClocking->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);

        // 2. Bucket clocking (WITH both clocked days and approved leave days)
        $empBoth = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Clocking And Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empBoth->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empBoth->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'days' => 2,
            'status' => 'approved',
        ]);

        // 3. Bucket stopped (clocked on 2026-07-01, then 5+ trailing working days missing)
        $empStopped = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Stopped Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empStopped->id,
            'date' => '2026-07-01',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);

        // 4. Bucket on leave (clocked === 0 && leaveDays > 0)
        $empOnLeave = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'On Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empOnLeave->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-15',
            'days' => 11,
            'status' => 'approved',
        ]);

        // Bucket never: $this->employee (created in setUp, no attendance, no leave)

        $data = $this->getScreenData();
        $totals = $data['totals'];
        $roster = $data['roster'];

        $this->assertSame(
            $totals['headcount'],
            $totals['bucketClocking'] + $totals['bucketStopped'] + $totals['bucketOnLeave'] + $totals['bucketNever']
        );
        $this->assertSame($totals['headcount'], count($roster));
    }

    public function test_an_employee_with_leave_and_clocked_days_counts_as_clocking(): void
    {
        $empBoth = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Clocked And On Leave Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empBoth->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $empBoth->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'days' => 2,
            'status' => 'approved',
        ]);

        $data = $this->getScreenData();
        $roster = collect($data['roster']);
        $row = $roster->firstWhere('id', $empBoth->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['onLeave']);
        $this->assertGreaterThan(0, $row['clocked']);
        $this->assertGreaterThan(0, $row['leaveDays']);

        $totals = $data['totals'];
        $this->assertGreaterThan(0, $totals['bucketClocking']);
        $this->assertSame(0, $totals['bucketOnLeave']);
    }
}
