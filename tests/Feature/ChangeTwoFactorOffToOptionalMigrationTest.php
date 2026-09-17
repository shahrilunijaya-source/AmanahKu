<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration has already run once (on empty tables) by the time RefreshDatabase
 * finishes, so each test seeds rows holding the old 'off' value and calls up() again.
 */
class ChangeTwoFactorOffToOptionalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_30_110100_change_two_factor_off_to_optional.php');
        $migration->up();
    }

    public function test_tenant_row_with_off_becomes_optional(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        DB::table('tenant_features')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'security.2fa',
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame('optional', DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'security.2fa')->value('value'));
    }

    public function test_platform_row_with_off_becomes_optional(): void
    {
        DB::table('platform_features')->insert([
            'key' => 'security.2fa',
            'value' => 'off',
            'locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame('optional', DB::table('platform_features')->where('key', 'security.2fa')->value('value'));
    }

    public function test_other_values_and_other_keys_are_left_alone(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        DB::table('tenant_features')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'security.2fa', 'value' => 'required', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'security.passkey', 'value' => 'off', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMigration();

        $this->assertSame('required', DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'security.2fa')->value('value'));
        $this->assertSame('off', DB::table('tenant_features')
            ->where('tenant_id', $tenant->id)->where('key', 'security.passkey')->value('value'));
    }
}
