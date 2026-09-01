<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The single-grid dashboard's contract (BuildsDashboardWidgets::dashboardData /
 * AppController::screen): which widgets a role gets, the pending-task queue routed
 * by the real reporting line, module gating, and the widget preference endpoint.
 *
 * Replaces DashboardScopeTest — the Me/Company scope switch it covered no longer
 * exists; role now decides per widget rather than per whole dashboard.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function userWithRole(string $role, string $email): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return $u;
    }

    private function employeeFor(User $user, ?int $reportsToId = null): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $user->name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** Every widget id laid out on the page, both columns flattened. @return list<string> */
    private function shownWidgets(): array
    {
        $layout = $this->get('/app/dash')->assertOk()->viewData('widgetLayout');

        return array_merge(...array_values($layout));
    }

    /** 1. A plain employee gets no team widget, and cannot force one on. */
    public function test_employee_never_gets_the_team_widgets(): void
    {
        $employee = $this->userWithRole('employee', 'employee@acme.test');
        $this->employeeFor($employee);
        $this->actAs($employee);

        $shown = $this->shownWidgets();
        $this->assertNotContains('attendance', $shown);
        $this->assertNotContains('stuck', $shown);
        $this->assertNotContains('pulse', $shown);

        // The picker only ever offers what the role is allowed, so nothing in the
        // catalog can be switched on to smuggle a gated widget back in.
        $catalog = collect($this->get('/app/dash')->viewData('widgetCatalog'))->pluck('id')->all();
        $this->assertNotContains('pulse', $catalog);
    }

    /** 2. A manager gets Team attendance; only the top tier gets the two oversight widgets. */
    public function test_role_decides_which_team_widgets_appear(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $this->employeeFor($manager);
        $this->actAs($manager);

        $shown = $this->shownWidgets();
        $this->assertContains('attendance', $shown);
        $this->assertNotContains('pulse', $shown);

        $hr = $this->userWithRole('hr', 'hr@acme.test');
        $this->employeeFor($hr);
        $this->actAs($hr);

        $shown = $this->shownWidgets();
        $this->assertContains('pulse', $shown);
        $this->assertContains('stuck', $shown);
    }

    /** 3. Pending tasks contains only requests actually routed to the viewer. */
    public function test_pending_tasks_only_contains_requests_routed_to_the_viewer(): void
    {
        $managerA = $this->userWithRole('manager', 'mgrA@acme.test');
        $empA = $this->employeeFor($managerA);
        $managerB = $this->userWithRole('manager', 'mgrB@acme.test');
        $empB = $this->employeeFor($managerB);

        $staffOfA = $this->userWithRole('employee', 'staffA@acme.test');
        $this->employeeFor($staffOfA, $empA->id);
        $staffOfB = $this->userWithRole('employee', 'staffB@acme.test');
        $this->employeeFor($staffOfB, $empB->id);

        $leaveType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        foreach ([$staffOfA, $staffOfB] as $staff) {
            LeaveRequest::create([
                'tenant_id' => $this->tenant->id,
                'employee_id' => Employee::where('user_id', $staff->id)->value('id'),
                'leave_type_id' => $leaveType->id, 'status' => 'submitted',
                'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
            ]);
        }

        $this->actAs($managerA);
        $groups = collect($this->get('/app/dash')->assertOk()->viewData('widgets')['tasks']['groups']);

        $approve = $groups->firstWhere('id', 'approve');
        $this->assertSame(1, $approve['count']);
        $this->assertStringContainsString($staffOfA->name, $approve['rows'][0]['title']);
    }

    /** 4. A tenant with `module.claims` off gets no claim rows in Pending tasks. */
    public function test_claims_off_drops_claim_rows_from_pending_tasks(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $mgrEmployee = $this->employeeFor($manager);
        $staff = $this->userWithRole('employee', 'staff@acme.test');
        $this->employeeFor($staff, $mgrEmployee->id);

        Claim::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => Employee::where('user_id', $staff->id)->value('id'),
            'status' => 'submitted', 'amount' => 50, 'date' => now(), 'type' => 'transport', 'title' => 'Taxi fare',
        ]);

        app(FeatureManager::class)->setTenant($this->tenant, 'module.claims', false);

        $this->actAs($manager);
        $groups = collect($this->get('/app/dash')->assertOk()->viewData('widgets')['tasks']['groups']);

        $this->assertNull($groups->firstWhere('id', 'approve'));
    }

    /** 5. A widget whose module is switched off is not built at all. */
    public function test_a_switched_off_module_takes_its_widget_with_it(): void
    {
        $employee = $this->userWithRole('employee', 'employee@acme.test');
        $this->employeeFor($employee);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.claims', false);

        $this->actAs($employee);
        $response = $this->get('/app/dash')->assertOk();

        $this->assertNotContains('claims', array_merge(...array_values($response->viewData('widgetLayout'))));
        $this->assertArrayNotHasKey('claims', $response->viewData('widgets'));
    }

    /** 6. `tasks` is pinned and can never be hidden through the prefs endpoint. */
    public function test_prefs_cannot_hide_the_pinned_task_list(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $this->employeeFor($manager);
        $this->actAs($manager);

        $this->postJson(route('dashboard.prefs.update'), [
            'hidden' => ['tasks', 'work'],
            'order' => ['left' => ['tasks', 'clock'], 'right' => ['calendar']],
        ])->assertOk();

        $manager->refresh();
        $prefs = $manager->dashboard_prefs['dash'];
        $this->assertNotContains('tasks', $prefs['hidden']);
        $this->assertContains('work', $prefs['hidden']);
        $this->assertSame(['tasks', 'clock'], $prefs['order']['left']);
        $this->assertSame(['calendar'], $prefs['order']['right']);
    }

    /** 7. A widget id that is not in the registry is rejected, not stored. */
    public function test_prefs_reject_an_unknown_widget_id(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $this->employeeFor($manager);
        $this->actAs($manager);

        $this->postJson(route('dashboard.prefs.update'), ['hidden' => ['not-a-widget']])
            ->assertStatus(422);
    }

    /** 8. A saved order drives the layout, and a widget it never saw still shows up. */
    public function test_saved_order_drives_the_layout(): void
    {
        $employee = $this->userWithRole('employee', 'employee@acme.test');
        $this->employeeFor($employee);
        $employee->dashboard_prefs = ['dash' => [
            'hidden' => [],
            'order' => ['left' => ['tasks', 'summary'], 'right' => []],
        ]];
        $employee->save();

        $this->actAs($employee);
        $layout = $this->get('/app/dash')->assertOk()->viewData('widgetLayout');

        $this->assertSame(['tasks', 'summary'], array_slice($layout['left'], 0, 2));
        // Never-dragged widgets fall back to their default column rather than vanishing.
        $this->assertContains('calendar', $layout['right']);
    }

    /** Team attendance names people the way colleagues say it, not by legal name. */
    public function test_team_attendance_uses_the_nickname(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $mgrEmployee = $this->employeeFor($manager);

        $staff = $this->userWithRole('employee', 'staff@acme.test');
        $staffEmployee = $this->employeeFor($staff, $mgrEmployee->id);
        $staffEmployee->update(['name' => 'Siti Nur Ain Akilah Binti Tarmizi', 'nickname' => 'akilah']);

        $this->actAs($manager);
        $people = $this->get('/app/dash')->assertOk()->viewData('widgets')['attendance']['people'];

        $this->assertSame('Akilah', $people[0]['name']);
        $this->assertSame('absent', $people[0]['state']);
    }

    /** Leave summary shows the biggest entitlements first and folds the tail away. */
    public function test_leave_summary_leads_with_the_biggest_entitlements_and_folds_the_rest(): void
    {
        $user = $this->userWithRole('employee', 'leave@acme.test');
        $employee = $this->employeeFor($user);

        foreach (['Unpaid' => 0, 'Annual' => 16, 'Marriage' => 3, 'Medical' => 14, 'Paternity' => 7] as $name => $days) {
            $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'entitlement' => $days]);
            LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'balance' => $days]);
        }

        $this->actAs($user);
        $response = $this->get('/app/dash')->assertOk();

        $types = array_column($response->viewData('widgets')['leave']['rows'], 'type');
        $this->assertSame(['Annual', 'Medical', 'Paternity', 'Marriage', 'Unpaid'], $types);

        // All five render; the two past the top three sit behind the fold, not dropped.
        $response->assertSee('Show 2 more')->assertSee('Marriage')->assertSee('Unpaid');
    }

    /** Only a few staff show, so whoever needs looking at comes first. */
    public function test_team_attendance_leads_with_the_days_that_need_attention(): void
    {
        $manager = $this->userWithRole('manager', 'attmgr@acme.test');
        $mgrEmployee = $this->employeeFor($manager);

        $names = ['Aisyah', 'Bala', 'Chong', 'Devi', 'Eng'];
        foreach ($names as $i => $name) {
            $staff = $this->userWithRole('employee', 'att'.$i.'@acme.test');
            $this->employeeFor($staff, $mgrEmployee->id)->update(['name' => $name]);
        }

        // Everyone is absent by default; give one of them an approved leave day so
        // the two states can be told apart in the order.
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => Employee::where('name', 'Aisyah')->value('id'),
            'leave_type_id' => $type->id, 'status' => 'approved',
            'date_from' => now(), 'date_to' => now(), 'days' => 1,
        ]);

        $this->actAs($manager);
        $response = $this->get('/app/dash')->assertOk();
        $people = $response->viewData('widgets')['attendance']['people'];

        // Absent first (alphabetically inside the state), the leave day after them.
        $this->assertSame(['Bala', 'Chong', 'Devi', 'Eng', 'Aisyah'], array_column($people, 'name'));

        // Three show, the other two sit behind the fold rather than being dropped.
        $response->assertSee('Show 2 more')->assertSee('Aisyah');
    }

    /** Calendar tabs widen the circle: Personal is you, Company is everyone. */
    public function test_calendar_entries_carry_the_narrowest_tab_that_shows_them(): void
    {
        $manager = $this->userWithRole('manager', 'calmgr@acme.test');
        $mgrEmployee = $this->employeeFor($manager);

        $staff = $this->userWithRole('employee', 'calstaff@acme.test');
        $staffEmployee = $this->employeeFor($staff, $mgrEmployee->id);
        $staffEmployee->update(['name' => 'Siti Aminah']);

        $stranger = $this->userWithRole('employee', 'calstranger@acme.test');
        $strangerEmployee = $this->employeeFor($stranger);
        $strangerEmployee->update(['name' => 'Raju Kumar']);

        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        foreach ([$mgrEmployee, $staffEmployee, $strangerEmployee] as $who) {
            LeaveRequest::create([
                'tenant_id' => $this->tenant->id, 'employee_id' => $who->id,
                'leave_type_id' => $type->id, 'status' => 'approved',
                'date_from' => now(), 'date_to' => now(), 'days' => 1,
            ]);
        }

        $this->actAs($manager);
        $calendar = $this->get('/app/dash')->assertOk()->viewData('widgets')['calendar'];

        $this->assertSame(['personal', 'team', 'company'], $calendar['calTabs']);

        $today = $calendar['days'][now()->toDateString()];
        $levels = collect($today['entries'])->pluck('level', 'who');
        $this->assertSame(0, $levels['You']);
        $this->assertSame(1, $levels['SA']);
        $this->assertSame(2, $levels['RK']);

        // Each tab's cell pills count only what that tab shows.
        $this->assertSame(1, $today['marks']['personal']['count']);
        $this->assertSame(2, $today['marks']['team']['count']);
        $this->assertSame(3, $today['marks']['company']['count']);
    }

    /** Your own leave shows before it is approved — it is your day either way. */
    public function test_calendar_shows_your_own_leave_while_it_is_still_waiting(): void
    {
        $user = $this->userWithRole('employee', 'calpending@acme.test');
        $employee = $this->employeeFor($user);

        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'leave_type_id' => $type->id, 'status' => 'submitted',
            'date_from' => now(), 'date_to' => now(), 'days' => 1,
        ]);

        $this->actAs($user);
        $calendar = $this->get('/app/dash')->assertOk()->viewData('widgets')['calendar'];

        $entries = $calendar['days'][now()->toDateString()]['entries'];
        $this->assertCount(1, $entries);
        $this->assertSame('pending', $entries[0]['kind']);
        $this->assertSame(0, $entries[0]['level']);
        $this->assertSame('Waiting to be verified', $entries[0]['sub']);
        $this->assertSame('You — annual', $entries[0]['title']);

        // Someone else's pending leave stays off the calendar, on every tab.
        $other = $this->userWithRole('employee', 'calpending2@acme.test');
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employeeFor($other)->id,
            'leave_type_id' => $type->id, 'status' => 'submitted',
            'date_from' => now(), 'date_to' => now(), 'days' => 1,
        ]);

        $this->actAs($user);
        $calendar = $this->get('/app/dash')->assertOk()->viewData('widgets')['calendar'];
        $this->assertSame(1, $calendar['days'][now()->toDateString()]['marks']['company']['count']);
    }

    /** Nobody reporting to you means no Team tab — it would only repeat Personal. */
    public function test_calendar_drops_the_team_tab_for_someone_with_no_reports(): void
    {
        $user = $this->userWithRole('employee', 'lonely@acme.test');
        $this->employeeFor($user);

        $this->actAs($user);
        $calendar = $this->get('/app/dash')->assertOk()->viewData('widgets')['calendar'];

        $this->assertSame(['personal', 'company'], $calendar['calTabs']);
    }
}
