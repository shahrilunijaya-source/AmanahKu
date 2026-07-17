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
 * Covers the two feature-toggle admin UIs: the super-admin platform matrix
 * (platform default + lock + tenant override) and the tenant HR settings panel
 * (toggle unlocked features; locked features are read-only and reject overrides).
 */
class FeatureToggleUiTest extends TestCase
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

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    // ── A. Super-admin matrix ─────────────────────────────────────

    public function test_super_admin_can_view_the_feature_matrix(): void
    {
        $this->actingAs($this->superAdmin())
            ->get("/admin/companies/{$this->tenant->slug}/features")
            ->assertOk()
            ->assertSee('Feature matrix')
            ->assertSee('module.payroll');
    }

    public function test_ordinary_user_cannot_view_the_matrix(): void
    {
        $this->actingHr()->get("/admin/companies/{$this->tenant->slug}/features")->assertForbidden();
    }

    public function test_super_admin_sets_a_platform_lock(): void
    {
        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$this->tenant->slug}/features", [
                'key' => 'security.2fa',
                'platform_value' => 'required',
                'locked' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('platform_features', [
            'key' => 'security.2fa', 'value' => 'required', 'locked' => 1,
        ]);

        // A locked key resolves to the platform value for the tenant, ignoring any override.
        $features = app(FeatureManager::class);
        $this->assertTrue($features->platformLocked('security.2fa'));
        $this->assertSame('required', $features->value($this->tenant, 'security.2fa'));
    }

    public function test_super_admin_can_seed_a_tenant_override_when_unlocked(): void
    {
        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$this->tenant->slug}/features", [
                'key' => 'module.payroll',
                'platform_value' => '1',
                'tenant_value' => '0',
                'set_tenant' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_features', [
            'tenant_id' => $this->tenant->id, 'key' => 'module.payroll', 'value' => '0',
        ]);
    }

    public function test_super_admin_override_is_rejected_when_key_is_locked(): void
    {
        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$this->tenant->slug}/features", [
                'key' => 'module.payroll',
                'platform_value' => '1',
                'locked' => '1',
                'tenant_value' => '0',
                'set_tenant' => '1',
            ])
            ->assertSessionHasErrors('tenant_value');

        // Platform default + lock still persisted; no tenant override written.
        $this->assertDatabaseHas('platform_features', ['key' => 'module.payroll', 'locked' => 1]);
        $this->assertDatabaseMissing('tenant_features', ['tenant_id' => $this->tenant->id, 'key' => 'module.payroll']);
    }

    public function test_matrix_rejects_an_unknown_key(): void
    {
        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$this->tenant->slug}/features", [
                'key' => 'module.bogus',
                'platform_value' => '1',
            ])
            ->assertStatus(422);
    }

    // ── B. Tenant HR settings panel ───────────────────────────────

    public function test_hr_sees_the_features_panel_on_settings(): void
    {
        $this->actingHr()->get('/app/settings')
            ->assertOk()
            ->assertSee('Save features');
    }

    public function test_tenant_toggles_an_unlocked_module_off(): void
    {
        // Submit the form with payroll unchecked → it should be turned off.
        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['module.leave' => '1'], // payroll omitted = off
        ])->assertRedirect();

        $this->assertDatabaseHas('tenant_features', [
            'tenant_id' => $this->tenant->id, 'key' => 'module.payroll', 'value' => '0',
        ]);
        $this->assertFalse(app(FeatureManager::class)->enabled($this->tenant, 'module.payroll'));
    }

    public function test_overtime_toggles_independently_of_leave(): void
    {
        $fm = app(FeatureManager::class);

        // Fresh tenant (no overrides): Overtime is its own module and defaults on.
        $this->assertTrue($fm->enabled($this->tenant, 'module.overtime'));

        // Turn Overtime off via the panel while keeping Leave & Time-off on.
        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['module.leave' => '1'], // module.overtime omitted = off
        ])->assertRedirect();

        $fm = app(FeatureManager::class); // fresh resolver — bypass request memo
        $this->assertFalse($fm->enabled($this->tenant, 'module.overtime'));
        $this->assertTrue($fm->enabled($this->tenant, 'module.leave'));

        // The gate fires on the overtime segment; Leave & Calendar are unaffected.
        $this->actingHr()->get('/app/overtime')->assertNotFound();
    }

    public function test_tenant_can_set_an_enum_setting(): void
    {
        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['security.2fa' => 'required'],
        ])->assertRedirect();

        $this->assertSame('required', app(FeatureManager::class)->value($this->tenant, 'security.2fa'));
    }

    public function test_tenant_attempt_to_change_a_locked_key_is_rejected(): void
    {
        // Platform locks payroll ON; tenant tries to turn it off → no-op.
        app(FeatureManager::class)->setPlatform('module.payroll', true, true);

        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['module.leave' => '1'], // payroll omitted (would be "off")
        ])->assertRedirect();

        // No tenant override row written for the locked key.
        $this->assertDatabaseMissing('tenant_features', [
            'tenant_id' => $this->tenant->id, 'key' => 'module.payroll',
        ]);
        // Resolved value stays at the locked platform value.
        $this->assertTrue(app(FeatureManager::class)->enabled($this->tenant, 'module.payroll'));
    }

    public function test_tenant_panel_never_writes_a_platform_scope_key(): void
    {
        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['platform.registration' => '0', 'module.leave' => '1'],
        ])->assertRedirect();

        // platform.registration is platform-scope — the panel must ignore it.
        $this->assertDatabaseMissing('tenant_features', [
            'tenant_id' => $this->tenant->id, 'key' => 'platform.registration',
        ]);
    }

    public function test_non_admin_cannot_post_features(): void
    {
        $emp = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $emp->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $emp->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($emp)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/admin/features', [
                'features_present' => '1',
                'features' => ['module.payroll' => '0'],
            ])->assertForbidden();

        $this->assertDatabaseMissing('tenant_features', [
            'tenant_id' => $this->tenant->id, 'key' => 'module.payroll',
        ]);
    }
}
