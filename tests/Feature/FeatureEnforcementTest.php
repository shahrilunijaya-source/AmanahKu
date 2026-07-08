<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature-flag enforcement: disabled modules vanish from nav and 404 their screens,
 * a 'required' 2FA policy funnels un-enrolled users to security, and the platform
 * registration switch closes /register. Core screens stay reachable throughout.
 */
class FeatureEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // ── Module gating ─────────────────────────────────────────────

    public function test_enabled_module_screen_and_nav_are_reachable(): void
    {
        $this->actingHr()->get('/app/payroll')
            ->assertOk()
            ->assertSee('Payroll');
    }

    public function test_disabled_module_screen_returns_404(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        $this->actingHr()->get('/app/payroll')->assertNotFound();
    }

    public function test_disabled_module_write_route_is_also_404ed(): void
    {
        // Security: the screen GET gate is not enough — a direct POST to a disabled
        // module's write route must also 404, not execute the controller.
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])
            ->assertNotFound();
    }

    public function test_core_write_route_unaffected_by_module_flags(): void
    {
        // Disabling payroll must not break a core (non-module) write path.
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        // attendance is core (not a toggleable module) — must not be 404ed.
        $res = $this->actingHr()->post('/app/attendance/clock');
        $this->assertNotSame(404, $res->status());
    }

    public function test_disabled_module_is_hidden_from_nav(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        // A core screen still renders; its nav must omit the disabled module's link.
        $html = $this->actingHr()->get('/app/dash')->assertOk()->getContent();

        $this->assertStringNotContainsString('>Payroll<', $html);
    }

    public function test_disabling_a_grouped_module_drops_the_whole_group(): void
    {
        // Performance gates every child of the "Performance" nav group.
        app(FeatureManager::class)->setTenant($this->tenant, 'module.performance', false);

        $this->actingHr()->get('/app/kpi')->assertNotFound();

        $html = $this->actingHr()->get('/app/dash')->assertOk()->getContent();
        $this->assertStringNotContainsString('Skills Matrix', $html);
    }

    public function test_core_screen_is_never_gated_by_a_module(): void
    {
        // Even with every module off, core surfaces stay reachable.
        foreach (array_keys(\App\Support\Features::MODULES) as $key) {
            app(FeatureManager::class)->setTenant($this->tenant, $key, false);
        }

        $this->actingHr()->get('/app/directory')->assertOk();
        $this->actingHr()->get('/app/settings')->assertOk();
        $this->actingHr()->get('/app/security')->assertOk();
    }

    // ── 2FA policy ────────────────────────────────────────────────

    public function test_required_2fa_funnels_unenrolled_user_to_security(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.2fa', 'required');

        $this->actingHr()->get('/app/dash')
            ->assertRedirect(route('app.screen', 'security'));
    }

    public function test_required_2fa_allows_the_security_screen_itself(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.2fa', 'required');

        $this->actingHr()->get('/app/security')->assertOk();
    }

    public function test_enrolled_user_is_not_funnelled_under_required_2fa(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.2fa', 'required');
        $this->hr->forceFill([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingHr()->get('/app/dash')->assertOk();
    }

    public function test_optional_2fa_does_not_funnel(): void
    {
        // Default policy is 'optional' — no funnel.
        $this->actingHr()->get('/app/dash')->assertOk();
    }

    public function test_user_with_no_active_tenant_is_never_trapped_by_2fa(): void
    {
        // A super-admin-style platform requirement must not trap a tenant-less user.
        app(FeatureManager::class)->setPlatform('security.2fa', 'required', true);

        $orphan = User::create(['name' => 'Orphan', 'email' => 'orphan@example.com', 'password' => Hash::make('password')]);

        $this->actingAs($orphan)->get('/tenant')->assertOk();
    }

    // ── AI assistant ──────────────────────────────────────────────

    public function test_disabled_ai_assistant_endpoint_is_forbidden(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'ai.assistant', false);

        $this->actingHr()->postJson('/app/assistant', ['message' => 'hi'])
            ->assertStatus(403);
    }

    public function test_enabled_ai_assistant_endpoint_replies(): void
    {
        $this->actingHr()->postJson('/app/assistant', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonStructure(['reply']);
    }

    // ── Payroll flags ─────────────────────────────────────────────

    public function test_auto_pcb_flag_drives_the_pcb_deduction(): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Payee', 'status' => 'active', 'workload' => 'green']);
        \App\Models\SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000]);

        // Default flag (off) → no auto-PCB.
        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-05'])->assertRedirect();
        $offSlip = \App\Models\PayrollRun::where('period', '2026-05')->firstOrFail()
            ->payslips()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame(0.0, (float) $offSlip->pcb);

        // Flag on → auto-PCB estimates the deduction (gross 5,000 → 110/mo).
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.auto_pcb', true);
        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])->assertRedirect();
        $onSlip = \App\Models\PayrollRun::where('period', '2026-06')->firstOrFail()
            ->payslips()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame(110.0, (float) $onSlip->pcb);
    }

    public function test_four_eyes_flag_blocks_finalizing_a_draft(): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Payee', 'status' => 'active', 'workload' => 'green']);
        \App\Models\SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000]);
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.four_eyes', true);

        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])->assertRedirect();
        $run = \App\Models\PayrollRun::where('period', '2026-06')->firstOrFail();

        // A draft cannot be finalized directly under four-eyes.
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertStatus(422);
        $this->assertSame('draft', $run->fresh()->status);

        // Approve, then finalize succeeds.
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_four_eyes_off_keeps_the_draft_finalize_shortcut(): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Payee', 'status' => 'active', 'workload' => 'green']);
        \App\Models\SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000]);

        $this->actingHr()->post('/app/payroll/runs', ['period' => '2026-06'])->assertRedirect();
        $run = \App\Models\PayrollRun::where('period', '2026-06')->firstOrFail();

        // Default (off) → a draft finalizes directly.
        $this->actingHr()->post("/app/payroll/runs/{$run->id}/finalize")->assertRedirect();
        $this->assertSame('finalized', $run->fresh()->status);
    }

    // ── Passkey policy ────────────────────────────────────────────

    public function test_passkey_off_hides_registration_card(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.passkey', 'off');

        $this->actingHr()->get('/app/security')
            ->assertOk()
            ->assertDontSee('Add passkey');
    }

    public function test_passkey_optional_shows_registration_card(): void
    {
        $this->actingHr()->get('/app/security')
            ->assertOk()
            ->assertSee('Add passkey');
    }

    // ── Public registration ───────────────────────────────────────

    public function test_registration_enabled_by_default_loads(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_registration_disabled_blocks_get(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);

        $this->get('/register')->assertRedirect('/login');
    }

    public function test_registration_disabled_blocks_post(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);

        $this->post('/register', [
            'name' => 'Nope', 'email' => 'nope@example.com',
            'password' => 'Sup3r-Secret-Pw!', 'password_confirmation' => 'Sup3r-Secret-Pw!',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'nope@example.com']);
    }

    public function test_login_still_works_when_registration_disabled(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);

        $this->get('/login')->assertOk();
    }
}
