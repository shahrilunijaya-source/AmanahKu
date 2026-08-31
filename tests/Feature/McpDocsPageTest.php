<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The staff-facing guide at /docs/mcp: how to connect Claude Code to AmanahKu.
 * Authenticated, unlike /docs/api — this page walks a signed-in person through their
 * own AI access key, so it belongs behind login. See McpDocsController for the reasoning.
 */
class McpDocsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $this->get('/docs/mcp')->assertRedirect('/login');
    }

    public function test_a_logged_in_staff_member_can_read_it(): void
    {
        $response = $this->actingAs($this->user)->get('/docs/mcp');

        $response->assertOk();
        $response->assertSee('Ask Claude about your AmanahKu account', escape: false);
    }

    public function test_it_links_to_account_and_security(): void
    {
        $response = $this->actingAs($this->user)->get('/docs/mcp');

        $response->assertSee(route('app.screen', 'security'), escape: false);
    }

    public function test_the_security_screen_links_to_the_guide(): void
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => Tenant::first()->id]);

        $response = $this->get(route('app.screen', 'security'));

        $response->assertOk();
        $response->assertSee(route('docs.mcp'), escape: false);
    }
}
