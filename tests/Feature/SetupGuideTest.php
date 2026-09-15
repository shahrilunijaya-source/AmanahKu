<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\CompanyCategory;
use App\Models\CompanySetupProgress;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
