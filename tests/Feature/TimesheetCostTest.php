<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Timesheet RM costing is derived from salary bands, so it must be visible to
 * HR & management only — never to line managers or the staff who log the hours.
 */
class TimesheetCostTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Employee $ownerEmployee;

    private Timesheet $timesheet;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        // 10,000 band → manday 900, manhour 112.50. An 8h week costs RM 900.00.
        $position = Position::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Project Manager', 'max_salary' => 10000,
        ]);

        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => Hash::make('password')]);
        $this->owner->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->ownerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id,
            'name' => 'Owner', 'status' => 'active', 'workload' => 'green', 'position_id' => $position->id,
        ]);

        $this->timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->ownerEmployee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $this->timesheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15', 'project' => 'KDN-iLPF', 'hours' => 8,
        ]);
    }

    private function actor(string $role): User
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function viewTimesheetsAs(User $user)
    {
        return $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])->get('/app/timesheets');
    }

    public function test_money_role_sees_rm_on_their_own_timesheet(): void
    {
        // The timesheets screen shows RM for the viewer's OWN weeks when they are a money role
        // (manager/management/HR). Cross-employee cost lives in the report, not here.
        $position = Position::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ops Lead', 'max_salary' => 10000,
        ]);
        $user = User::create(['name' => 'Mgr', 'email' => 'ownmgr@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green', 'position_id' => $position->id,
        ]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $ts->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15', 'project' => 'X', 'hours' => 8,
        ]);

        $this->viewTimesheetsAs($user)->assertOk()->assertSee('RM 900.00');
    }

    public function test_staff_does_not_see_rm_cost_on_their_own_timesheet(): void
    {
        // Plain staff never see salary-derived cost, even on their own timesheet.
        $this->viewTimesheetsAs($this->owner)->assertOk()->assertDontSee('RM 900.00');
    }

    // ---- Timesheet cost report (by category / project / staff) -----------

    private function viewReportAs(User $user)
    {
        // Explicit period so the seeded June 2026 entry is always in range, regardless of clock.
        return $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/timesheet-reports?from=2026-06-01&to=2026-06-30');
    }

    public function test_hr_report_shows_total_rm_cost(): void
    {
        $this->viewReportAs($this->actor('hr'))->assertOk()->assertSee('RM 900.00');
    }

    public function test_manager_can_open_report_and_see_cost(): void
    {
        $this->viewReportAs($this->actor('manager'))->assertOk()->assertSee('RM 900.00');
    }

    public function test_management_report_shows_cost(): void
    {
        $this->viewReportAs($this->actor('management'))->assertOk()->assertSee('RM 900.00');
    }

    public function test_plain_employee_cannot_open_the_timesheet_report(): void
    {
        $this->viewReportAs($this->owner)->assertForbidden();
    }
}
