<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Company-wide "See all" dock links + the read-only Team Board screen are visible to
 * management, HR, and immediate superiors (anyone with a direct report) — and hidden
 * from plain employees. Guards both the dock affordance and the screen's own gate.
 */
class TeamBoardAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** Acting user with a role and a linked Employee record; returns the Employee. */
    private function actor(string $role): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role).$this->seq, 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function staff(string $name, ?int $reportsToId = null): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function task(Employee $owner, string $title, string $status = 'todo'): WorkItem
    {
        return WorkItem::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $owner->id,
            'title' => $title, 'type' => 'task', 'status' => $status, 'sort_order' => 1,
        ]);
    }

    public function test_hr_can_open_team_board_and_sees_every_task(): void
    {
        $this->actor('hr');
        $bob = $this->staff('Bob Cruncher');
        $this->task($bob, 'Ship the payroll run');

        $this->get('/app/team-board')->assertOk()->assertSee('Ship the payroll run');
    }

    public function test_manager_can_open_team_board(): void
    {
        $this->actor('manager');
        $this->task($this->staff('Bob Cruncher'), 'Review Q3 numbers');

        $this->get('/app/team-board')->assertOk()->assertSee('Review Q3 numbers');
    }

    public function test_immediate_superior_employee_can_open_team_board(): void
    {
        // Role is plain 'employee', but the org chart gives them a direct report.
        $boss = $this->actor('employee');
        $this->staff('Direct Report', $boss->id);

        $this->get('/app/team-board')->assertOk();
    }

    public function test_plain_employee_without_reports_is_forbidden(): void
    {
        $this->actor('employee');

        $this->get('/app/team-board')->assertForbidden();
    }

    public function test_personal_screens_show_the_see_all_icon_for_a_superior(): void
    {
        $this->actor('hr');

        // Each personal screen carries an icon button to its company-wide counterpart.
        $this->get('/app/attendance')->assertOk()->assertSee('See all staff attendance');
        $this->get('/app/board')->assertOk()->assertSee('See all staff tasks');
        $this->get('/app/timesheets')->assertOk()->assertSee('See all staff timesheets');
    }

    public function test_personal_screens_hide_the_see_all_icon_from_a_plain_employee(): void
    {
        $this->actor('employee');

        $this->get('/app/attendance')->assertOk()->assertDontSee('See all staff attendance');
        $this->get('/app/board')->assertOk()->assertDontSee('See all staff tasks');
        $this->get('/app/timesheets')->assertOk()->assertDontSee('See all staff timesheets');
    }
}
