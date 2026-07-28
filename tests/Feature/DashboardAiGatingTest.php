<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dashboard render coverage for all four personas, plus the `module.ai` gate that
 * hides the AI Workforce Intelligence dashboard blocks (manager's "AI recommendations"
 * panel; management's capacity/risk/next-steps cards) — off by default since
 * `Features::NOT_READY`, restorable per-tenant with a single feature flip (docs/ISSUES.md
 * I-025).
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

    private function dashAs(User $u, string $persona)
    {
        $this->actingAs($u)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => $persona,
        ]);

        return $this->get('/app/dash');
    }

    /**
     * An HR-role user may preview every persona (Amanahku::PERSONA_ACCESS), so one
     * account exercises all four dashboard branches — including management and hr,
     * which previously had zero render coverage.
     */
    public function test_dashboard_renders_for_every_persona(): void
    {
        $hr = $this->userWithRole('hr', 'hr@example.com');

        foreach (['employee', 'manager', 'management', 'hr'] as $persona) {
            $this->dashAs($hr, $persona)->assertOk();
        }
    }

    public function test_ai_blocks_are_hidden_by_default(): void
    {
        // Asserts the shipped default, so opt out of the suite-wide "every module on".
        $this->useShippedModuleDefaults();

        $hr = $this->userWithRole('hr', 'hr@example.com');

        $this->dashAs($hr, 'manager')->assertOk()->assertDontSee('AI recommendations');

        $management = $this->dashAs($hr, 'management')->assertOk();
        $management->assertDontSee('What management should do next');
        $management->assertDontSee('Open full intelligence view');
    }

    public function test_ai_blocks_appear_when_module_ai_is_enabled(): void
    {
        $hr = $this->userWithRole('hr', 'hr@example.com');
        app(FeatureManager::class)->setTenant($this->tenant, 'module.ai', true);

        $this->dashAs($hr, 'manager')->assertOk()->assertSee('AI recommendations');

        $management = $this->dashAs($hr, 'management')->assertOk();
        $management->assertSee('What management should do next');
        $management->assertSee('Open full intelligence view');
    }

    public function test_manager_with_no_direct_reports_sees_team_status_empty_state(): void
    {
        $manager = $this->userWithRole('manager', 'mgr@example.com');

        $this->dashAs($manager, 'manager')->assertOk()->assertSee('No direct reports yet');
    }
}
