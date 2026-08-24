<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard's two-scope contract (BuildsDashboardData::dashboardScopeData /
 * AppController::screen): `me` vs `company`, the company queue routed by the real
 * reporting line, module gating, pinned card prefs, and the live queue-count
 * headline copy.
 */
class DashboardScopeTest extends TestCase
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

    /** 1. An `employee` role cannot reach `scope=company` — it falls back to `me`. */
    public function test_employee_cannot_reach_company_scope(): void
    {
        $employee = $this->userWithRole('employee', 'employee@acme.test');
        $this->employeeFor($employee);
        $this->actAs($employee);

        $response = $this->get('/app/dash?scope=company');

        $response->assertOk();
        $this->assertSame('me', $response->viewData('scope'));
    }

    /** 2. The company queue contains only requests actually routed to the viewer. */
    public function test_company_queue_only_contains_requests_routed_to_the_viewer(): void
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

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => Employee::where('user_id', $staffOfA->id)->value('id'),
            'leave_type_id' => $leaveType->id, 'status' => 'submitted',
            'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => Employee::where('user_id', $staffOfB->id)->value('id'),
            'leave_type_id' => $leaveType->id, 'status' => 'submitted',
            'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
        ]);

        $this->actAs($managerA);
        $response = $this->get('/app/dash?scope=company');
        $response->assertOk();

        $queue = collect($response->viewData('queue'));
        $this->assertCount(1, $queue);
        $this->assertStringContainsString($staffOfA->name, $queue->first()['title']);
    }

    /** 3. A tenant with `module.claims` off gets no claim rows in the company queue. */
    public function test_claims_off_drops_claim_rows_from_the_queue(): void
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
        $response = $this->get('/app/dash?scope=company');
        $response->assertOk();

        $this->assertCount(0, collect($response->viewData('queue')));
    }

    /** 4. Pinned cards (`queue`, `secondary`) can never be hidden via the prefs endpoint. */
    public function test_card_prefs_cannot_hide_pinned_cards(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $this->employeeFor($manager);
        $this->actAs($manager);

        $response = $this->postJson(route('dashboard.prefs.update'), [
            'scope' => 'company',
            'hidden' => ['queue', 'secondary', 'rot'],
            'order' => ['queue', 'rot', 'pop', 'news', 'secondary'],
        ]);

        $response->assertOk();

        $manager->refresh();
        $hidden = $manager->dashboard_prefs['company']['hidden'] ?? [];
        $this->assertNotContains('queue', $hidden);
        $this->assertNotContains('secondary', $hidden);
        $this->assertContains('rot', $hidden);
    }

    /** 5. `$head['h1']` reads correctly for 0, 1 and 4 queue items. */
    public function test_company_head_h1_reflects_the_queue_count(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@acme.test');
        $mgrEmployee = $this->employeeFor($manager);
        $this->actAs($manager);

        // 0 — no direct reports, no requests.
        $response = $this->get('/app/dash?scope=company');
        $this->assertSame('Nothing is waiting on you.', $response->viewData('head')['h1']);

        $leaveType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        $staff = $this->userWithRole('employee', 'staff1@acme.test');
        $this->employeeFor($staff, $mgrEmployee->id);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => Employee::where('user_id', $staff->id)->value('id'),
            'leave_type_id' => $leaveType->id, 'status' => 'submitted',
            'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
        ]);

        // 1 — one submitted leave request awaiting verification.
        $response = $this->get('/app/dash?scope=company');
        $this->assertSame('One request is waiting on you.', $response->viewData('head')['h1']);

        for ($i = 2; $i <= 4; $i++) {
            $s = $this->userWithRole('employee', "staff{$i}@acme.test");
            $this->employeeFor($s, $mgrEmployee->id);
            LeaveRequest::create([
                'tenant_id' => $this->tenant->id,
                'employee_id' => Employee::where('user_id', $s->id)->value('id'),
                'leave_type_id' => $leaveType->id, 'status' => 'submitted',
                'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
            ]);
        }

        // 4 — four submitted leave requests awaiting verification.
        $response = $this->get('/app/dash?scope=company');
        $this->assertSame('Four requests are waiting on you.', $response->viewData('head')['h1']);
    }

    /** The "Around you" rail card shows the nickname people actually say, not the full legal name. */
    public function test_around_you_card_uses_nickname(): void
    {
        $viewer = $this->userWithRole('employee', 'viewer@acme.test');
        $this->employeeFor($viewer);

        $colleague = $this->userWithRole('employee', 'colleague@acme.test');
        $colleagueEmployee = $this->employeeFor($colleague);
        $colleagueEmployee->update(['name' => 'Siti Nur Ain Akilah Binti Tarmizi', 'nickname' => 'akilah']);

        $leaveType = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $colleagueEmployee->id,
            'leave_type_id' => $leaveType->id, 'status' => 'approved',
            'date_from' => now()->addDay(), 'date_to' => now()->addDay(), 'days' => 1,
        ]);

        $this->actAs($viewer);
        $rows = collect($this->get('/app/dash')->viewData('railCards'))->firstWhere('id', 'around')['rows'];

        $this->assertSame('Akilah', $rows[0]['title']);
        $this->assertSame('Annual leave', $rows[0]['meta']);
    }
}
