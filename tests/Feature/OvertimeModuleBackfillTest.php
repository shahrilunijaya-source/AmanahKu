<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the module.leave → module.overtime split: splitting Overtime into its own
 * default-true key would silently re-enable it for tenants that had disabled Leave. The
 * backfill data-migration must copy each tenant's stored module.leave value into module.overtime
 * so prior intent is preserved.
 */
class OvertimeModuleBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_07_08_000002_backfill_module_overtime_from_module_leave.php');
        $migration->up();
    }

    public function test_a_tenant_that_disabled_leave_keeps_overtime_disabled(): void
    {
        $tenant = Tenant::create(['slug' => 'left-off', 'name' => 'LeftOff', 'initials' => 'LO']);
        DB::table('tenant_features')->insert([
            'tenant_id' => $tenant->id, 'key' => 'module.leave', 'value' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertDatabaseHas('tenant_features', [
            'tenant_id' => $tenant->id, 'key' => 'module.overtime', 'value' => '0',
        ]);
        app(CurrentTenant::class)->set($tenant);
        $this->assertFalse(app(FeatureManager::class)->enabled($tenant, 'module.overtime'));
    }

    public function test_a_tenant_that_kept_leave_on_is_left_enabled(): void
    {
        $tenant = Tenant::create(['slug' => 'left-on', 'name' => 'LeftOn', 'initials' => 'LN']);
        DB::table('tenant_features')->insert([
            'tenant_id' => $tenant->id, 'key' => 'module.leave', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();

        $this->assertDatabaseHas('tenant_features', [
            'tenant_id' => $tenant->id, 'key' => 'module.overtime', 'value' => '1',
        ]);
    }

    public function test_a_tenant_with_no_leave_override_is_untouched(): void
    {
        $tenant = Tenant::create(['slug' => 'defaulted', 'name' => 'Defaulted', 'initials' => 'DF']);

        $this->runBackfill();

        $this->assertDatabaseMissing('tenant_features', [
            'tenant_id' => $tenant->id, 'key' => 'module.overtime',
        ]);
        // No override → resolves to the registry default (true); overtime stays enabled by default.
        app(CurrentTenant::class)->set($tenant);
        $this->assertTrue(app(FeatureManager::class)->enabled($tenant, 'module.overtime'));
    }

    public function test_backfill_is_idempotent_and_does_not_clobber_a_later_choice(): void
    {
        $tenant = Tenant::create(['slug' => 'idem', 'name' => 'Idem', 'initials' => 'ID']);
        DB::table('tenant_features')->insert([
            'tenant_id' => $tenant->id, 'key' => 'module.leave', 'value' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runBackfill();
        // Admin later re-enables overtime explicitly.
        DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'module.overtime')
            ->update(['value' => '1']);
        $this->runBackfill(); // second run must not overwrite the newer explicit choice

        $rows = DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'module.overtime')->count();
        $this->assertSame(1, $rows);
        $this->assertSame('1', DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'module.overtime')->value('value'));
    }
}
