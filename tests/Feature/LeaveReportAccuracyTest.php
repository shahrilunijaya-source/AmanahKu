<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\LeaveReportController;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
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
 * Arithmetic on the Leave Reports screen. Four figures were wrong:
 *
 *   1. the pending count obeyed neither the period nor the department filter,
 *   2. headcount ignored the department, so "avg days / head" divided one team's days
 *      by company headcount,
 *   3. leave straddling a period edge was booked whole into the period it started in,
 *   4. the planned-balance column was resolved by the literal name 'Annual'.
 *
 * Each test pins one of them against the controller's own output.
 */
class LeaveReportAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $annual;

    private Department $ops;

    private Department $sales;

    private Employee $viewer;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Every period on this screen is relative to "now", so the clock is fixed.
        $this->travelTo(CarbonImmutable::parse('2026-07-30 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->annual = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16,
        ]);
        $this->ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operations']);
        $this->sales = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);
        $this->viewer = $this->member('Report Viewer');
    }

    private function member(string $name, ?Department $dept = null, ?float $balance = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'department_id' => $dept?->id,
        ]);

        if ($balance !== null) {
            LeaveBalance::create([
                'employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'balance' => $balance,
            ]);
        }

        return $employee;
    }

    private function leave(Employee $e, string $from, string $to, float $days, string $status = 'approved'): LeaveRequest
    {
        return $e->leaveRequests()->create([
            'tenant_id' => $this->tenant->id,
            'leave_type_id' => $this->annual->id,
            'date_from' => $from, 'date_to' => $to, 'days' => $days, 'status' => $status,
        ]);
    }

    /** Run the controller the way the screen does. Company scope = no employee limit. */
    private function report(array $query = []): array
    {
        $request = Request::create('/app/leave-report', 'GET', $query);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('employee', $this->viewer);

        return app(LeaveReportController::class)->screenData($request);
    }

    public function test_the_pending_count_respects_the_department_filter(): void
    {
        $opsStaff = $this->member('Ops Person', $this->ops, 16);
        $salesStaff = $this->member('Sales Person', $this->sales, 16);

        $this->leave($opsStaff, '2026-03-02', '2026-03-03', 2, 'submitted');
        $this->leave($salesStaff, '2026-03-04', '2026-03-05', 2, 'verified');

        $this->assertSame(2, $this->report(['period' => 'ytd'])['kpis']['pending']);
        $this->assertSame(1, $this->report(['period' => 'ytd', 'dept' => 'Operations'])['kpis']['pending']);
    }

    public function test_the_pending_count_respects_the_period(): void
    {
        $staff = $this->member('Ops Person', $this->ops, 16);
        // One inside the 90-day window, one far outside it.
        $this->leave($staff, '2026-07-20', '2026-07-21', 2, 'submitted');
        $this->leave($staff, '2020-01-06', '2020-01-07', 2, 'submitted');

        $this->assertSame(1, $this->report(['period' => 'quarter'])['kpis']['pending']);
    }

    public function test_average_per_head_divides_by_the_filtered_headcount(): void
    {
        $ops1 = $this->member('Ops One', $this->ops, 16);
        $this->member('Ops Two', $this->ops, 16);
        // Staff outside the department, so an unfiltered denominator would be obvious.
        $this->member('Sales One', $this->sales, 16);
        $this->member('Sales Two', $this->sales, 16);

        $this->leave($ops1, '2026-03-02', '2026-03-05', 4);

        $ops = $this->report(['period' => 'ytd', 'dept' => 'Operations']);

        // 4 days over the 2 Operations staff, not over every employee in the tenant.
        $this->assertSame(2, $ops['kpis']['headcount']);
        $this->assertSame(2.0, $ops['kpis']['avgPerHead']);
    }

    public function test_leave_across_a_year_boundary_counts_only_its_days_inside_the_period(): void
    {
        $staff = $this->member('Ops Person', $this->ops, 16);
        // 28 Dec 2025 – 6 Jan 2026 is ten days, six of which fall in 2026.
        $this->leave($staff, '2025-12-28', '2026-01-06', 10);

        $ytd = $this->report(['period' => 'ytd']);

        // 1–6 Jan is six calendar days: Thu, Fri, the TOT Saturday (first Saturday of
        // the month, 0.5), a Sunday (not a working day), Mon, Tue.
        $this->assertSame(4.5, $ytd['kpis']['totalDays'], '1–6 Jan is four and a half working days of the ten');
        $this->assertSame(4.5, (float) $ytd['byStaff'][0]['totalDays']);
        $this->assertSame(4.5, (float) $ytd['byType'][0]['days']);
    }

    public function test_a_half_day_counts_as_half_a_day(): void
    {
        $staff = $this->member('Ops Person', $this->ops, 16);
        $this->leave($staff, '2026-03-02', '2026-03-02', 0.5)->update(['half_day_period' => 'am']);

        $this->assertSame(0.5, $this->report(['period' => 'ytd'])['kpis']['totalDays']);
    }

    /**
     * The balance column used to be found by the literal string 'Annual'. It is now the
     * type unplanned leave deducts from, so renaming it keeps the column populated.
     */
    public function test_the_planned_balance_type_survives_being_renamed(): void
    {
        $this->annual->update(['name' => 'Cuti Tahunan']);
        LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Kecemasan', 'entitlement' => 0,
            'is_unplanned' => true, 'deducts_from_leave_type_id' => $this->annual->id,
        ]);

        $staff = $this->member('Ops Person', $this->ops, 9.5);
        $this->leave($staff, '2026-03-02', '2026-03-03', 2);

        $ytd = $this->report(['period' => 'ytd']);

        $this->assertSame('Cuti Tahunan', $ytd['balanceTypeName']);
        $this->assertSame(9.5, (float) $ytd['byStaff'][0]['annualRemaining']);
    }

    /** A missing balance row stays null, so the screen can say "not set up" not "0". */
    public function test_a_staff_member_with_no_balance_row_reports_null_not_zero(): void
    {
        $noBalance = $this->member('No Balance', $this->ops);
        $zeroBalance = $this->member('Zero Balance', $this->ops, 0);

        $this->leave($noBalance, '2026-03-02', '2026-03-02', 1);
        $this->leave($zeroBalance, '2026-03-03', '2026-03-03', 1);

        $byName = collect($this->report(['period' => 'ytd'])['byStaff'])->keyBy('name');

        $this->assertNull($byName['No Balance']['annualRemaining']);
        $this->assertSame(0.0, (float) $byName['Zero Balance']['annualRemaining']);
    }
}
