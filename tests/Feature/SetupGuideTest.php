<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\CompanySetupProgress;
use App\Models\Employee;
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
}
