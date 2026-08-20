<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

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

        $this->hrUser = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
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

    private function actAsHr(): self
    {
        return $this->actingAs($this->hrUser)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ]);
    }

    public function test_an_employee_with_no_records_is_named_on_the_screen(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Never Clocked',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->actAsHr()->get('/app/attendance-report')
            ->assertOk()
            ->assertSee('Never Clocked');
    }

    public function test_each_working_day_produces_a_row(): void
    {
        $response = $this->actAsHr()->get('/app/attendance-report?gran=week');
        $response->assertOk();

        $this->assertSame(
            count($response->viewData('workingDays')),
            substr_count($response->getContent(), 'uj-ar-cols uj-ar-row'),
            'one row per working day for the single employee'
        );
    }

    public function test_approved_leave_reads_as_leave_not_absence(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->hrEmployee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'approved',
        ]);

        $this->actAsHr()->get('/app/attendance-report?gran=week')
            ->assertOk()
            ->assertSee('On leave')
            ->assertSee('Annual Leave');
    }

    public function test_a_missing_clock_out_row_is_flagged_and_offers_a_fix(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->hrEmployee->id,
            'date' => '2026-07-14', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsHr()->get('/app/attendance-report?gran=week&lens=miss')
            ->assertOk()
            ->assertSee('data-alert', false)
            ->assertSee('Missing')
            ->assertSee('day=2026-07-14', false);
    }

    public function test_the_totals_row_reports_hours(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->hrEmployee->id,
            'date' => '2026-07-14', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540, 'flags' => [],
        ]);

        $this->actAsHr()->get('/app/attendance-report?gran=week')
            ->assertOk()
            ->assertSee('9.0')
            ->assertSee('total hours');
    }

    public function test_a_lens_narrows_the_rows_while_the_totals_hold_still(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->hrEmployee->id,
            'date' => '2026-07-14', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $plain = $this->actAsHr()->get('/app/attendance-report?gran=week');
        $lensed = $this->actAsHr()->get('/app/attendance-report?gran=week&lens=miss');

        $this->assertGreaterThan(
            substr_count($lensed->getContent(), 'uj-ar-cols uj-ar-row'),
            substr_count($plain->getContent(), 'uj-ar-cols uj-ar-row')
        );
        $this->assertSame($plain->viewData('totals'), $lensed->viewData('totals'));
    }

    public function test_on_a_single_day_the_date_column_is_dropped(): void
    {
        // All 34 rows would otherwise repeat the same date. The CSS keys off this.
        $this->actAsHr()->get('/app/attendance-report?gran=day')
            ->assertOk()
            ->assertSee('data-gran="day"', false);
    }

    public function test_a_plain_employee_cannot_reach_the_report(): void
    {
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password'),
        ]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $staff->id,
            'name' => 'Staff Person', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($staff)
            ->withSession(['current_tenant' => $this->tenant->id, 'persona' => 'employee'])
            ->get('/app/attendance-report')
            ->assertForbidden();
    }

    public function test_the_export_button_carries_the_current_filters(): void
    {
        $this->actAsHr()->get('/app/attendance-report?gran=week&lens=late&dept=Finance')
            ->assertOk()
            ->assertSee('attendance-report/export', false)
            ->assertSee('lens=late', false)
            ->assertSee('dept=Finance', false);
    }

    public function test_the_screen_works_without_javascript(): void
    {
        // Every filter is a link or a GET form, so the period, the lens and the sort
        // all still change with scripting off.
        $html = $this->actAsHr()->get('/app/attendance-report?gran=week')->assertOk()->getContent();

        $this->assertStringContainsString('gran=month', $html, 'the period segment is a link');
        $this->assertStringContainsString('lens=miss', $html, 'the lens chips are links');
        $this->assertStringContainsString('sort=person', $html, 'the sort segment is a link');
    }

    public function test_the_ledger_gets_the_wide_measure(): void
    {
        // Eight dense columns do not fit the 920px focused measure the roster used.
        $this->actAsHr()->get('/app/attendance-report')
            ->assertOk()
            ->assertSee('uj-main--wide', false);
    }
}
