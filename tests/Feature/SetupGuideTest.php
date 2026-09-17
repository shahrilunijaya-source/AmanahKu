<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\CompanySetupProgress;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\SetupGuide;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The live setup guide: the dock, the sidebar ring and the coachmark pointer that walk
 * the first HR through Launch Center until setup is finished.
 */
class SetupGuideTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tenant,1:User} a fresh tenant at the given stage + its HR admin (the only employee). */
    private function company(int $level = 1): array
    {
        $category = CompanyCategory::where('level', $level)->first();
        $tenant = Tenant::create(['slug' => 'acme'.$level, 'name' => 'Acme '.$level, 'initials' => 'A'.$level, 'company_category_id' => $category->id]);
        app(FeatureManager::class)->applyCategoryPackage($tenant, $level);

        $hr = User::create(['name' => 'HR', 'email' => 'hr'.$level.'@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green', 'initials' => 'HR', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return [$tenant, $hr];
    }

    /** @return User a plain staff member of the tenant. */
    private function staff(Tenant $tenant): User
    {
        $u = User::create(['name' => 'Staff', 'email' => 'staff'.Employee::count().'@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'name' => 'Staff', 'status' => 'active', 'workload' => 'green', 'initials' => 'ST', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return $u;
    }

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** Re-run the stamping migration against whatever rows the test has set up. */
    private function runStampMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_30_000000_stamp_setup_complete_for_live_tenants.php');
        $migration->up();
    }

    // ── Migration ──────────────────────────────────────────────────────────────

    public function test_migration_stamps_tenants_with_staff_and_leaves_fresh_ones_alone(): void
    {
        [$live] = $this->company(1);
        $this->staff($live); // two active employees → "has staff"
        [$fresh] = $this->company(2); // only the HR

        // A live tenant that never had a progress row, and one that has an unfinished row.
        app(CurrentTenant::class)->set($fresh);
        CompanySetupProgress::forCurrentTenant();

        $this->runStampMigration();

        $this->assertNotNull(DB::table('company_setup_progress')->where('tenant_id', $live->id)->value('completed_at'));
        $this->assertNull(DB::table('company_setup_progress')->where('tenant_id', $fresh->id)->value('completed_at'));
    }

    public function test_migration_stamps_an_existing_unfinished_row_without_duplicating_it(): void
    {
        [$live] = $this->company(1);
        $this->staff($live);
        app(CurrentTenant::class)->set($live);
        CompanySetupProgress::forCurrentTenant()->update(['steps' => ['modules']]);

        $this->runStampMigration();

        $this->assertSame(1, DB::table('company_setup_progress')->where('tenant_id', $live->id)->count());
        $row = DB::table('company_setup_progress')->where('tenant_id', $live->id)->first();
        $this->assertNotNull($row->completed_at);
        $this->assertSame(['modules'], json_decode($row->steps, true));
    }

    public function test_migration_skips_archived_employees_when_counting_staff(): void
    {
        [$tenant] = $this->company(1);
        $this->staff($tenant);
        Employee::where('name', 'Staff')->update(['archived_at' => now()]);

        $this->runStampMigration();

        $this->assertNull(DB::table('company_setup_progress')->where('tenant_id', $tenant->id)->value('completed_at'));
    }

    // ── Step copy ──────────────────────────────────────────────────────────────

    public function test_every_step_carries_bilingual_guide_copy(): void
    {
        [$tenant] = $this->company(2); // stage 2 → payroll on → payroll_setup present
        app(CurrentTenant::class)->set($tenant);

        foreach (app(SetupController::class)->stepDefs() as $key => $def) {
            $this->assertNotSame('', trim($def['guide'] ?? ''), "step {$key} has no guide copy");
            $this->assertNotSame('', trim($def['guide_ms'] ?? ''), "step {$key} has no guide_ms copy");
        }
    }

    // ── SetupGuide::forRequest ────────────────────────────────────────────────

    /** A request as ResolveTenant would leave it for a member with the given role. */
    private function requestAs(string $role): Request
    {
        $request = Request::create('/app/dash');
        $request->attributes->set('tenantRole', $role);

        return $request;
    }

    public function test_guide_lists_every_step_in_launch_center_order_with_done_flags(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);

        $guide = SetupGuide::forRequest($this->requestAs('hr'));

        $this->assertNotNull($guide);
        $keys = array_column($guide['steps'], 'key');
        $this->assertSame(array_keys(app(SetupController::class)->stepDefs()), $keys);
        $this->assertSame('modules', $keys[0]);
        $this->assertSame('review', end($keys));

        $byKey = array_column($guide['steps'], null, 'key');
        $this->assertTrue($byKey['branches']['done']);
        $this->assertFalse($byKey['departments']['done']);
        $this->assertSame(route('app.screen', ['screen' => 'settings']), $byKey['branches']['url']);
        $this->assertSame('setup', $byKey['branches']['nav']); // no sidebar row for settings
        $this->assertSame('staff-load', $byKey['staff']['nav']);
        $this->assertSame(route('app.screen', ['screen' => 'leave-setup', 'tab' => 'holidays']), $byKey['holidays']['url']);
        $this->assertSame('Go to Company Settings and click + Add on the Branches card. Give it a name and address; the map pin can wait.', $byKey['branches']['guide']);
        $this->assertSame(count($keys), $guide['total']);
        $this->assertSame(count(array_filter($guide['steps'], fn ($s) => $s['done'])), $guide['done']);
    }

    public function test_guide_is_null_for_plain_staff_and_managers(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);

        $this->assertNull(SetupGuide::forRequest($this->requestAs('employee')));
        $this->assertNull(SetupGuide::forRequest($this->requestAs('manager')));
    }

    public function test_guide_shows_for_hr_management_and_directors(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);

        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('hr')));
        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('management')));
        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('director')));
    }

    public function test_guide_is_null_once_setup_is_finished(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        $this->assertNull(SetupGuide::forRequest($this->requestAs('hr')));
    }

    public function test_guide_is_null_without_a_tenant(): void
    {
        $this->assertNull(SetupGuide::forRequest($this->requestAs('hr')));
    }

    public function test_guide_only_lists_the_payroll_step_when_the_module_is_on(): void
    {
        // module.payroll ships in Features::OFF, so no category package turns it on; the
        // tenant (or super-admin) has to flip it explicitly.
        [$tenant] = $this->company(2);
        app(CurrentTenant::class)->set($tenant);
        $this->assertNotContains('payroll_setup', array_column(SetupGuide::forRequest($this->requestAs('hr'))['steps'], 'key'));

        app(FeatureManager::class)->setTenant($tenant, 'module.payroll', true);

        $this->assertContains('payroll_setup', array_column(SetupGuide::forRequest($this->requestAs('hr'))['steps'], 'key'));
    }

    // ── Coachmark $when ───────────────────────────────────────────────────────

    public function test_coachmark_with_when_renders_the_expression_and_never_writes_localstorage(): void
    {
        $html = view('partials.coachmark', [
            'key' => 'guide-branches',
            'when' => "\$store.guide.current === 'branches'",
            'en' => ['title' => 'Add your first branch', 'body' => 'Click + Add.'],
        ])->render();

        $this->assertStringContainsString("get show() { return ! this.closed && (\$store.guide.current === 'branches'); }", $html);
        $this->assertStringContainsString('closed: false', $html);
        $this->assertStringNotContainsString("localStorage.setItem('amanahku-coach-guide-branches'", $html);
        $this->assertStringNotContainsString("localStorage.getItem('amanahku-coach-guide-branches')", $html);
        $this->assertStringContainsString('uj-coach-bubble', $html);
    }

    public function test_coachmark_without_when_still_remembers_dismissal_in_localstorage(): void
    {
        $html = view('partials.coachmark', [
            'key' => 'plain',
            'en' => ['title' => 'T', 'body' => 'B'],
        ])->render();

        $this->assertStringContainsString("show: localStorage.getItem('amanahku-coach-plain') !== '1'", $html);
        $this->assertStringContainsString("localStorage.setItem('amanahku-coach-plain', '1')", $html);
        $this->assertStringNotContainsString('get show()', $html);
    }

    // ── Dock rendering ────────────────────────────────────────────────────────

    public function test_dock_renders_for_hr_on_a_fresh_tenant_with_the_ordered_step_json(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->getContent();

        $this->assertStringContainsString('data-guide-dock', $html);
        $this->assertStringContainsString("Alpine.store('guide'", $html);
        $this->assertMatchesRegularExpression('/id="uj-guide-steps"[^>]*>\s*\[\{"key":"modules"/', $html);
        $this->assertStringContainsString('"key":"review"', $html);
        $this->assertStringContainsString('amanahku-guide-skip', $html);
        $this->assertStringContainsString('amanahku-guide-collapsed', $html);
        $this->assertStringContainsString('amanahku-guide-last', $html);
        // The deep link is the one Launch Center uses.
        $this->assertStringContainsString(json_encode(route('app.screen', ['screen' => 'leave-setup', 'tab' => 'holidays'])), $html);
    }

    /** Satisfy every launch-critical detector so the launch lock lets staff in (mirrors OnboardingWizardTest::launch). */
    private function launch(Tenant $tenant): void
    {
        $dept = Department::create(['tenant_id' => $tenant->id, 'name' => 'IT']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);
        $branch->forceFill(['latitude' => 3.1, 'longitude' => 101.6])->save();
        Position::create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'title' => 'Developer', 'max_salary' => 5000]);
        LeaveType::create(['tenant_id' => $tenant->id, 'name' => 'Annual', 'entitlement' => 14]);
    }

    public function test_dock_is_not_rendered_for_plain_staff(): void
    {
        [$tenant] = $this->company(1);
        $this->launch($tenant);
        $staff = $this->staff($tenant);
        // Past the profile gate too, so a real app screen renders (not the welcome wizard).
        Employee::where('user_id', $staff->id)->firstOrFail()->update(['nric' => '900101015555', 'date_of_birth' => '1990-01-01', 'phone' => '0123456789', 'address' => '1 Jalan Test', 'emergency_contact_name' => 'Kin', 'emergency_contact_phone' => '0198887777']);

        $html = $this->actingAs($staff)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertOk()->getContent();

        // Setup is still unfinished (HR would see the dock); staff get neither the dock
        // nor the step data, only the empty store.
        $this->assertStringNotContainsString('data-guide-dock', $html);
        $this->assertStringNotContainsString('id="uj-guide-steps"', $html);
        $this->assertStringContainsString("Alpine.store('guide'", $html);
    }

    public function test_dock_disappears_after_finish(): void
    {
        [$tenant, $hr] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('data-guide-dock', false);
    }

    public function test_dock_is_hidden_for_a_tenant_the_migration_stamped(): void
    {
        [$tenant, $hr] = $this->company(1);
        $this->staff($tenant);
        $this->runStampMigration();

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('data-guide-dock', false);
    }

    public function test_superadmin_browsing_a_new_company_sees_the_dock(): void
    {
        [$tenant] = $this->company(1);
        $super = $this->superAdmin();

        $this->actingAs($super)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertSee('data-guide-dock', false);
    }

    public function test_dock_step_list_only_carries_payroll_when_the_module_is_on(): void
    {
        [$tenant, $hr] = $this->company(2);
        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('"key":"payroll_setup"', false);

        app(FeatureManager::class)->setTenant($tenant, 'module.payroll', true);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertSee('"key":"payroll_setup"', false);
    }

    public function test_embedded_screens_get_the_store_but_not_the_dock(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings?embed=1&section=branches')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-guide-dock', $html);
        $this->assertStringContainsString('"key":"branches"', $html);
    }

    // ── Sidebar ring ──────────────────────────────────────────────────────────

    public function test_sidebar_rows_bind_the_guide_ring_to_their_screens(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->getContent();

        // Section rows bind the whole section's screen list (@js emits JSON.parse(...)),
        // so the ring finds the Administration row while its panel is closed.
        $this->assertMatchesRegularExpression('/uj-nav-row[^>]*:class="\$store\.guide\.on\(JSON\.parse\(/', $html);
        // Leaf rows bind their own id as a literal. Company Settings has no sidebar row
        // (config screens are reached through Company Setup), so its steps ring 'setup'.
        $this->assertStringContainsString("\$store.guide.on(['staff-load'])", $html);
        $this->assertStringContainsString("\$store.guide.on(['setup'])", $html);
    }

    // ── Coachmark pointers on the target screens ──────────────────────────────

    public function test_settings_carries_pointers_for_modules_branches_and_departments(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString("\$store.guide.current === 'modules'", $html);
        $this->assertStringContainsString("\$store.guide.current === 'branches'", $html);
        $this->assertStringContainsString("\$store.guide.current === 'departments'", $html);
    }

    public function test_each_critical_screen_carries_its_pointer(): void
    {
        [$tenant, $hr] = $this->company(1);
        $as = fn () => $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id]);

        $as()->get('/app/position')->assertOk()->assertSee("\$store.guide.current === 'positions'", false);
        $as()->get('/app/staff-load')->assertOk()->assertSee("\$store.guide.current === 'staff'", false);
        $as()->get('/app/attendance-admin')->assertOk()->assertSee("\$store.guide.current === 'attendance_policy'", false);
        $as()->get('/app/leave-setup')->assertOk()->assertSee("\$store.guide.current === 'leave_types'", false);
        $as()->get('/app/timesheet-setup')->assertOk()->assertSee("\$store.guide.current === 'timesheet_categories'", false);
    }

    public function test_pointers_are_absent_once_setup_is_finished_and_the_store_is_empty(): void
    {
        [$tenant, $hr] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        // The include still renders (it is a static Blade include) but the store has no
        // steps, so the expression is false and the bubble never shows; what must be
        // absent is any step data that could make it true.
        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="uj-guide-steps"', $html);
        $this->assertStringNotContainsString('"key":"branches"', $html);
    }
}
