<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard render coverage for both scopes (`me` and `company`) across every
 * role. AI Workforce Intelligence (module.ai / the `workload` screen) is a
 * descoped module (Features::OFF) with no dashboard presence any more — the
 * old manager "AI recommendations" panel and management "capacity & risk"
 * cards were part of the four-persona dashboard this trait replaced.
 */
class DashboardAiGatingTest extends TestCase
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
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $u;
    }

    private function dashAs(User $u, ?string $scope = null)
    {
        $this->actingAs($u)->withSession(['current_tenant' => $this->tenant->id]);

        return $this->get('/app/dash'.($scope ? "?scope={$scope}" : ''));
    }

    /** Both scopes render for every role that may reach them. */
    public function test_dashboard_renders_for_every_role_and_scope(): void
    {
        $employee = $this->userWithRole('employee', 'employee@example.com');
        $manager = $this->userWithRole('manager', 'manager@example.com');
        $hr = $this->userWithRole('hr', 'hr@example.com');
        $management = $this->userWithRole('management', 'management@example.com');

        $this->dashAs($employee)->assertOk();

        foreach ([$manager, $hr, $management] as $u) {
            $this->dashAs($u, 'me')->assertOk();
            $this->dashAs($u, 'company')->assertOk();
        }
    }

    public function test_ai_workforce_module_has_no_dashboard_presence(): void
    {
        // module.ai / 'workload' is a descoped module — off by default and not
        // referenced anywhere in the new two-scope dashboard.
        $this->useShippedModuleDefaults();

        $boss = $this->userWithRole('management', 'management@example.com');

        $this->dashAs($boss, 'company')->assertOk()
            ->assertDontSee('AI recommendations')
            ->assertDontSee('What management should do next')
            ->assertDontSee('Open full intelligence view');
    }

    public function test_company_scope_renders_with_no_pending_actions(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@example.com');

        // No direct reports means an empty queue — the screen still renders.
        $this->dashAs($manager, 'company')->assertOk();
    }
}
