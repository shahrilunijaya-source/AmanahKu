<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FunStarterContentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require base_path('database/migrations/2026_09_11_120000_seed_fun_starter_content.php'))->up();
    }

    public function test_it_seeds_unijaya_once_and_leaves_other_tenants_alone(): void
    {
        $other = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $unijaya = Tenant::create(['slug' => 'unijaya-resources-sdn-bhd', 'name' => 'Unijaya', 'initials' => 'UJ']);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(30, DB::table('wrapped_arcs')->where('tenant_id', $unijaya->id)->count());
        $this->assertSame(3, DB::table('side_quests')->where('tenant_id', $unijaya->id)->where('status', 'live')->count());
        $this->assertSame(2, DB::table('plot_twist_polls')->where('tenant_id', $unijaya->id)->where('status', 'open')->count());
        $this->assertSame(6, DB::table('plot_twist_options')->count());
        // The dev ids point at nobody here, so they fall back to null instead of failing.
        $this->assertNull(DB::table('side_quests')->value('created_by'));

        $this->assertSame(0, DB::table('wrapped_arcs')->where('tenant_id', $other->id)->count());
        $this->assertSame(0, DB::table('side_quests')->where('tenant_id', $other->id)->count());
    }

    public function test_it_does_nothing_without_the_unijaya_tenant(): void
    {
        $this->runMigration();

        $this->assertSame(0, DB::table('wrapped_arcs')->count());
        $this->assertSame(0, DB::table('side_quests')->count());
        $this->assertSame(0, DB::table('plot_twist_polls')->count());
    }
}
