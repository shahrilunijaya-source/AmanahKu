<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\Features;
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

    public function test_descoped_modules_are_hidden_from_the_tenant_panel(): void
    {
        $this->useShippedModuleDefaults();

        $this->actingHr()->get('/app/settings')
            ->assertOk()
            ->assertSee('module.leave')       // live module — still toggleable
            ->assertDontSee('module.payroll') // descoped, blade deleted, resolved off
            ->assertDontSee('module.overtime');
    }

    public function test_a_descoped_module_a_company_already_enabled_stays_toggleable(): void
    {
        // An override that beats Features::OFF must keep its row, or the company
        // could never switch the module back off again.
        $this->useShippedModuleDefaults();
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', true);

        $this->actingHr()->get('/app/settings')
            ->assertOk()
            ->assertSee('module.payroll');
    }

    public function test_super_admin_matrix_still_lists_descoped_modules(): void
    {
        // The matrix is the only way back for a descoped module, so it must not filter.
        $this->useShippedModuleDefaults();

        $this->actingAs($this->superAdmin())->get("/admin/companies/{$this->tenant->slug}/features")
            ->assertOk()
            ->assertSee('module.overtime');
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

    // ── C. Malay copy on the company card ─────────────────────────

    public function test_malay_module_and_setting_labels_appear_on_the_settings_page(): void
    {
        $html = $this->actingHr()->get('/app/settings')->assertOk()->getContent();

        // module.documents (shipped, not in Features::OFF) and security.2fa, both
        // rendered through the x-text pattern (@js-encoded, no special characters
        // in either string so a plain substring check is safe).
        $this->assertStringContainsString('Peti Dokumen', $html);
        $this->assertStringContainsString('Pengesahan dua faktor', $html);
    }

    public function test_a_module_missing_from_the_nav_falls_back_to_lain_lain(): void
    {
        // module.messages's only screen ('messages') is not in Amanahku::nav(), and the
        // module itself isn't in Features::OFF, so a plain HTTP request really hits the
        // section-fallback branch without any special test harness.
        $html = $this->actingHr()->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString('Lain-lain', $html);
    }

    public function test_setting_enum_options_show_malay_choice_text(): void
    {
        $html = $this->actingHr()->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString('Pilihan', $html); // security.2fa optional
        $this->assertStringContainsString('Wajib', $html);   // security.2fa required
    }

    public function test_two_factor_off_option_was_removed_from_the_registry(): void
    {
        $this->assertArrayNotHasKey('off', Features::SETTINGS['security.2fa']['options']);
    }

    public function test_submitting_2fa_off_is_rejected_and_the_value_is_unchanged(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.2fa', 'required');

        $this->actingHr()->post('/app/admin/features', [
            'features_present' => '1',
            'features' => ['security.2fa' => 'off', 'module.leave' => '1'],
        ])->assertRedirect();

        // 'off' is no longer in security.2fa's options, so validateFeatureValue()
        // returns null and the whole key is rejected rather than written.
        $this->assertSame('required', app(FeatureManager::class)->value($this->tenant, 'security.2fa'));
    }

    public function test_ai_assistant_setting_is_hidden_from_the_company_card_by_default(): void
    {
        $this->actingHr()->get('/app/settings')
            ->assertOk()
            ->assertDontSee('ai.assistant');
    }

    public function test_ai_assistant_setting_shows_once_a_company_already_has_it_on(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'ai.assistant', true);

        $this->actingHr()->get('/app/settings')
            ->assertOk()
            ->assertSee('ai.assistant');
    }

    public function test_the_modules_coachmark_no_longer_mentions_payroll(): void
    {
        $html = $this->actingHr()->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString('your company uses, then click Save features', $html);
        $this->assertStringNotContainsString('adds the payroll step', $html);
    }

    public function test_staff_level_seniority_help_shown_while_adding_and_editing(): void
    {
        $level = StaffLevel::create(['tenant_id' => $this->tenant->id, 'name' => 'L1', 'rank' => 1]);

        $html = $this->actingHr()->get('/app/settings')->assertOk()->getContent();

        // Each block renders the line twice (the x-text ternary + the static fallback
        // content): once in the always-present "adding" form, once in this level's own
        // edit form. One staff level → 4 raw occurrences.
        $this->assertSame(4, substr_count($html, 'A smaller number means more senior'));

        // Structural: the edit-scoped copy sits within this level's own edit form, not
        // just present somewhere else on the page.
        $editForm = 'x-show="editId === '.$level->id.'"';
        $afterForm = strstr($html, $editForm);
        $this->assertNotFalse($afterForm);
        $this->assertStringContainsString('A smaller number means more senior', substr($afterForm, 0, 2000));
    }

    // ── D. Registry data integrity ──────────────────────────────────

    public function test_every_module_has_a_malay_label(): void
    {
        $this->assertSame(array_keys(Features::MODULES), array_keys(Features::MODULE_LABELS_MS));
    }

    public function test_tenant_settings_with_malay_copy_have_label_ms_and_help_ms(): void
    {
        foreach (['security.2fa', 'security.passkey', 'ai.assistant', 'payroll.four_eyes', 'claims.medical_cap'] as $key) {
            $meta = Features::meta($key);
            $this->assertArrayHasKey('label_ms', $meta, $key);
            $this->assertArrayHasKey('help_ms', $meta, $key);
        }
    }

    public function test_enum_options_ms_keys_match_options_keys(): void
    {
        foreach (Features::SETTINGS as $key => $meta) {
            if (($meta['type'] ?? null) !== 'enum' || ! isset($meta['options_ms'])) {
                continue;
            }
            $this->assertSame(array_keys($meta['options']), array_keys($meta['options_ms']), $key);
        }
    }
}
