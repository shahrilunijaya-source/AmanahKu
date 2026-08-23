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

    /**
     * Give $role a costed employee record plus one submitted 8h week (RM 900.00), and
     * return the user, so a role's view of its OWN money can be asserted either way.
     */
    private function actorWithOwnCostedWeek(string $role): User
    {
        $this->seq++;
        $position = Position::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ops Lead '.$this->seq, 'max_salary' => 10000,
        ]);
        $user = User::create(['name' => ucfirst($role), 'email' => "own{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'position_id' => $position->id,
        ]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $ts->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15', 'project' => 'X', 'hours' => 8,
        ]);

        return $user;
    }

    public function test_money_role_no_longer_sees_rm_on_their_own_timesheet(): void
    {
        // The Review tab's "My weeks" RM list was removed (2026-08-18): it's now a
        // read-only weekly entry view for every role, with no cost figures at all —
        // money-role cost lives only in the all-staff timesheet-reports screen now.
        $this->viewTimesheetsAs($this->actorWithOwnCostedWeek('hr'))->assertOk()->assertDontSee('RM 900.00');
    }

    public function test_manager_does_not_see_rm_on_their_own_timesheet(): void
    {
        // A line manager is not a money role: RM comes from the salary band, and a manager
        // has no business reading a salary-derived figure, not even their own.
        $this->viewTimesheetsAs($this->actorWithOwnCostedWeek('manager'))->assertOk()->assertDontSee('RM 900.00');
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
        // Formatted "RM 900.00" is client-rendered (Alpine x-text) since the totals shelf
        // was dropped; assert the server-rendered payload it reads from instead.
        $this->viewReportAs($this->actor('hr'))->assertOk()->assertSee('cost\\u0022:900', false);
    }

    public function test_manager_cannot_open_the_all_staff_report(): void
    {
        // The all-staff view is a salary-derived cost report, so it is management/HR only.
        // A line manager reads their team's time on the team screens, never their money.
        $this->viewReportAs($this->actor('manager'))->assertForbidden();
    }

    public function test_management_report_shows_cost(): void
    {
        $this->viewReportAs($this->actor('management'))->assertOk()->assertSee('cost\\u0022:900', false);
    }

    public function test_director_report_shows_cost(): void
    {
        // 'director' is a management super-set (Permissions::effectiveRole), so it must pass
        // both the screen gate and the money gate — the money gate used the raw role string
        // before, which let a director in and then showed them a report with no RM in it.
        $this->viewReportAs($this->actor('director'))->assertOk()->assertSee('cost\\u0022:900', false);
    }

    public function test_plain_employee_cannot_open_the_timesheet_report(): void
    {
        $this->viewReportAs($this->owner)->assertForbidden();
    }
}
