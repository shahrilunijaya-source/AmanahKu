<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\CreateCompanyInviteTool;
use App\Models\CompanyInvite;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The one single-step write on the MCP server: a director or HR mints a
 * self-serve company signup link in one call, no preview/confirm.
 */
class CreateCompanyInviteToolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        app(CurrentTenant::class)->set(null);
        Carbon::setTestNow('2026-09-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => "$role@example.com", 'password' => Hash::make('secret')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green']);

        return $user;
    }

    /** @param list<string> $abilities */
    private function callTool(User $user, array $arguments, array $abilities = ['*']): TestResponse
    {
        $token = $user->mintApiToken($this->tenant, 'test', $abilities);
        Auth::forgetGuards();

        return $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => app(CreateCompanyInviteTool::class)->name(), 'arguments' => $arguments],
        ], ['Authorization' => 'Bearer '.$token->plainTextToken]);
    }

    private function data(TestResponse $r): array
    {
        return json_decode($r->json('result.content.0.text'), true) ?? [];
    }

    public function test_hr_mints_a_link_in_one_call(): void
    {
        $hr = $this->userWithRole('hr');

        $r = $this->callTool($hr, ['stage' => 2, 'note' => 'For Petron']);

        $this->assertFalse((bool) $r->json('result.isError'), $r->getContent());
        $invite = CompanyInvite::sole();
        $data = $this->data($r);

        $this->assertSame($invite->url(), $data['url']);
        $this->assertStringContainsString('/register?invite='.$invite->token, $data['url']);
        $this->assertSame($hr->id, $invite->created_by_user_id);
        $this->assertSame('For Petron', $invite->note);
        $this->assertSame(2, $invite->category->level);
        $this->assertNull($invite->used_at);
        $this->assertTrue($invite->expires_at->equalTo(now()->addDays(7)));
    }

    public function test_director_mints_too(): void
    {
        $r = $this->callTool($this->userWithRole('director'), ['stage' => 1]);

        $this->assertFalse((bool) $r->json('result.isError'), $r->getContent());
        $this->assertSame(1, CompanyInvite::sole()->category->level);
    }

    public function test_manager_and_employee_are_refused(): void
    {
        foreach (['manager', 'employee'] as $role) {
            $r = $this->callTool($this->userWithRole($role), ['stage' => 2]);
            $this->assertTrue((bool) $r->json('result.isError'), $role);
            $this->assertStringContainsString('director or HR', $r->json('result.content.0.text'));
        }
        $this->assertSame(0, CompanyInvite::count());
    }

    public function test_token_without_scope_is_refused(): void
    {
        $r = $this->callTool($this->userWithRole('hr'), ['stage' => 2], ['board:write']);

        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertStringContainsString('invites:write', $r->json('result.content.0.text'));
        $this->assertSame(0, CompanyInvite::count());
    }

    public function test_unknown_stage_is_refused(): void
    {
        $r = $this->callTool($this->userWithRole('hr'), ['stage' => 3]);

        $this->assertTrue((bool) $r->json('result.isError'));
        $this->assertSame(0, CompanyInvite::count());
    }
}
