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
use App\Services\DataScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The ledger payload: rows synthesized per employee per working day, scope-only
 * totals, and the lens that narrows the rows without touching them.
 */
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

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function screenData(array $query): array
    {
        $request = Request::create('/app/attendance-report', 'GET', $query);
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('employee', $this->employee);

        return app(AttendanceReportController::class)->screenData($request);
    }

    /**
     * Stubs DataScope so the scope tests do not depend on org-chart fixtures.
     *
     * @param  array<string, string>  $query
     * @param  list<int>  $visibleIds
     * @return array<string, mixed>
     */
    private function screenDataScoped(array $query, array $visibleIds): array
    {
        $this->mock(DataScope::class, function ($mock) use ($visibleIds) {
            $mock->shouldReceive('visibleEmployeeIds')->andReturn($visibleIds);
        });

        return $this->screenData($query);
    }

    public function test_an_employee_with_no_records_still_appears_in_the_ledger(): void
    {
        $data = $this->screenData(['gran' => 'week']);

        $this->assertContains(
            $this->employee->name,
            $data['rows']->pluck('name')->all(),
            'a person with zero records must still occupy rows'
        );
    }

    public function test_approved_leave_is_not_counted_as_absence(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'approved',
        ]);

        $this->assertSame(
            'leave',
            $this->screenData(['gran' => 'week'])['rows']->firstWhere('date', '2026-07-14')['status']
        );

        // The other weekdays in the window really are unclocked, so the count is not
        // zero — the claim is that the leave day itself is not one of them.
        $this->assertNotContains(
            '2026-07-14',
            $this->screenData(['gran' => 'week', 'lens' => 'absent'])['rows']->pluck('date')->all()
        );
    }

    public function test_pending_leave_does_not_excuse_an_absence(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'pending',
        ]);

        $this->assertSame('absent', $this->screenData(['gran' => 'week'])['rows']->firstWhere('date', '2026-07-14')['status']);
    }

    public function test_a_narrowed_data_scope_hides_other_staff(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenDataScoped(['gran' => 'week'], [$this->employee->id]);

        $this->assertNotContains('Someone Else', $data['rows']->pluck('name')->all());
        $this->assertSame(1, $data['totals']['staff']);
    }

    public function test_an_archived_employee_gets_no_rows(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Left The Company',
            'status' => 'resigned', 'workload' => 'green',
        ]);

        $this->assertNotContains(
            'Left The Company',
            $this->screenData(['gran' => 'week'])['rows']->pluck('name')->all()
        );
    }

    public function test_a_weekend_record_is_added_to_the_working_days(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-12', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00',
            'status' => 'on_time', 'worked_minutes' => 480, 'flags' => [],
        ]);

        $data = $this->screenData(['gran' => 'custom', 'from' => '2026-07-10', 'to' => '2026-07-14']);

        $this->assertContains('2026-07-12', $data['workingDays'], 'a Sunday somebody worked');
    }

    public function test_the_default_period_is_the_calendar_month_to_date(): void
    {
        $data = $this->screenData([]);

        $this->assertSame('month', $data['gran']);
        $this->assertSame('2026-07-01', $data['from']);
        $this->assertSame('2026-07-15', $data['to']);
    }

    public function test_a_department_filter_narrows_the_totals(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Finance Person',
            'department_id' => $dept->id, 'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenData(['gran' => 'week', 'dept' => 'Finance']);

        $this->assertSame(1, $data['totals']['staff']);
        $this->assertSame(['Finance Person'], $data['rows']->pluck('name')->unique()->all());
    }

    public function test_a_name_search_narrows_the_totals(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zainab Osman',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenData(['gran' => 'week', 'q' => 'zainab']);

        $this->assertSame(['Zainab Osman'], $data['rows']->pluck('name')->unique()->all());
        $this->assertSame(1, $data['totals']['staff']);
    }

    public function test_a_lens_narrows_the_rows_but_not_the_totals(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-14', 'clock_in' => '09:41:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $plain = $this->screenData(['gran' => 'week']);
        $lensed = $this->screenData(['gran' => 'week', 'lens' => 'miss']);

        $this->assertLessThan($plain['rows']->count(), $lensed['rows']->count());
        $this->assertSame(
            $plain['totals'],
            $lensed['totals'],
            'the caption says "to date"; a lens must not rewrite what that means'
        );
    }

    public function test_a_drill_through_outside_the_viewers_scope_is_refused(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Out Of Scope',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenDataScoped(['emp' => (string) $other->id], [$this->employee->id]);

        $this->assertNull($data['person']);
    }

    public function test_the_drawer_carries_the_remark_and_the_photo_the_row_has_no_room_for(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-14', 'clock_in' => '09:41:00', 'clock_out' => '18:00:00',
            'status' => 'late', 'worked_minutes' => 499, 'flags' => [],
            'clock_in_justification' => 'Stuck on the Federal Highway.',
            'photo_path' => 'attendance-photos/in.jpg',
        ]);

        $day = $this->screenData(['gran' => 'week', 'emp' => (string) $this->employee->id])['person']['days']->firstWhere('date', '2026-07-14');

        $this->assertSame('Stuck on the Federal Highway.', $day['noteIn']);
        $this->assertNotNull($day['photoIn']);
        $this->assertNull($day['photoOut'], 'no clock-out selfie was captured');
    }
}
