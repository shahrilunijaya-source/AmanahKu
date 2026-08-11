<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "Segment" toggle and the inline creation panel it reveals share one Alpine
 * `x-data="{ addSeg: ... }"` scope. They were once split into sibling divs — the
 * panel closed the scope div before `x-show="addSeg"` was reached — which threw
 * "Can't find variable: addSeg" in production (Sentry PHP-LARAVEL-D).
 */
class KnowledgeBankAddSegmentScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_segment_panel_stays_inside_the_addseg_scope(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);

        $user = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Viewer', 'status' => 'active', 'workload' => 'green',
        ]);

        $html = $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/knowledge-bank')
            ->assertOk()
            ->getContent();

        $dataPos = strpos($html, 'x-data="{ addSeg:');
        $this->assertNotFalse($dataPos, 'addSeg x-data scope not found on the page.');

        $showPos = strpos($html, 'x-show="addSeg"');
        $this->assertNotFalse($showPos, 'addSeg toggle panel not found on the page.');

        $openTagStart = strrpos(substr($html, 0, $dataPos), '<div');
        $between = substr($html, $openTagStart, $showPos - $openTagStart);
        $depth = substr_count($between, '<div') - substr_count($between, '</div>');

        $this->assertGreaterThan(0, $depth, 'addSeg panel sits outside the x-data scope that defines addSeg.');
    }
}
