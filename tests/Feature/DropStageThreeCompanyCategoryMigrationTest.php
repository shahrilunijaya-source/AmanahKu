<?php

namespace Tests\Feature;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\TenantFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration has already run by the time RefreshDatabase finishes (Stage 3 is gone
 * for every test), so the "up() runs again later" case is exercised by re-creating a
 * Stage 3 row and calling up() a second time, same pattern as
 * ForgetFailedTinkerTestJobsMigrationTest.
 */
class DropStageThreeCompanyCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_30_110000_drop_stage_three_company_category.php');
        $migration->up();
    }

    public function test_stage_three_is_already_gone_after_normal_test_migrations(): void
    {
        $this->assertNull(CompanyCategory::where('level', 3)->first());
        $this->assertNotNull(CompanyCategory::where('level', 2)->first());
    }

    public function test_running_up_again_repoints_tenants_and_invites_without_touching_feature_ticks(): void
    {
        $stage2 = CompanyCategory::where('level', 2)->firstOrFail();
        $now = now();
        $stage3Id = DB::table('company_categories')->insertGetId([
            'key' => 'stage-3', 'level' => 3,
            'name' => 'Stage 3 — Intelligent HR',
            'description' => 'test row',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'company_category_id' => $stage3Id,
        ]);
        TenantFeature::create(['tenant_id' => $tenant->id, 'key' => 'module.claims', 'value' => '1']);
        TenantFeature::create(['tenant_id' => $tenant->id, 'key' => 'module.ai', 'value' => '0']);
        $ticksBefore = TenantFeature::where('tenant_id', $tenant->id)->orderBy('key')->pluck('value', 'key')->all();

        $invite = CompanyInvite::factory()->create(['company_category_id' => $stage3Id]);

        $this->runMigration();

        $this->assertSame($stage2->id, $tenant->fresh()->company_category_id);
        $this->assertSame($stage2->id, $invite->fresh()->company_category_id);
        $this->assertNull(CompanyCategory::where('level', 3)->first());
        $this->assertSame(
            $ticksBefore,
            TenantFeature::where('tenant_id', $tenant->id)->orderBy('key')->pluck('value', 'key')->all(),
            'the migration must not re-apply a feature package'
        );
    }
}
