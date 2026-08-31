<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the self-service AI access key card on the Account & security screen
 * (SecurityController::generateAiKey / revokeAiKey). Harness copied from LoanTest.
 */
class SecurityAiKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function tokensFor(User $user): Collection
    {
        return PersonalAccessToken::where('tokenable_id', $user->id)->get();
    }

    public function test_security_screen_renders_with_no_key_yet(): void
    {
        $response = $this->actingInTenant()->get(route('app.screen', 'security'));

        $response->assertOk();
        $this->assertStringContainsString('AI access key', $response->getContent());
    }

    public function test_wrong_password_is_rejected_and_mints_nothing(): void
    {
        $response = $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'nope']);

        $response->assertSessionHasErrors('password');
        $this->assertCount(0, $this->tokensFor($this->user));
    }

    public function test_generates_a_key_with_exactly_the_three_read_abilities_and_tenant(): void
    {
        $response = $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password']);

        $response->assertRedirect();
        $token = $this->tokensFor($this->user)->first();
        $this->assertNotNull($token);
        $this->assertSame(['timesheets:read', 'board:read', 'tot:read'], $token->abilities);
        $this->assertSame($this->tenant->id, $token->tenant_id);
        $this->assertSame('claude-code', $token->name);
    }

    public function test_ticking_allow_writes_mints_all_six_abilities(): void
    {
        $response = $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password', 'allow_writes' => '1']);

        $response->assertRedirect();
        $token = $this->tokensFor($this->user)->first();
        $this->assertNotNull($token);
        $this->assertSame(
            ['timesheets:read', 'board:read', 'tot:read', 'board:write', 'timesheets:write', 'tot:write'],
            $token->abilities,
        );
    }

    public function test_regenerating_without_the_box_drops_write_scopes(): void
    {
        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password', 'allow_writes' => '1']);
        $this->assertContains('board:write', $this->tokensFor($this->user)->first()->abilities);

        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password']);

        $tokens = $this->tokensFor($this->user);
        $this->assertCount(1, $tokens);
        $this->assertSame(['timesheets:read', 'board:read', 'tot:read'], $tokens->first()->abilities);
    }

    public function test_generating_again_leaves_exactly_one_key(): void
    {
        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password']);
        $first = $this->tokensFor($this->user)->first();

        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password']);

        $tokens = $this->tokensFor($this->user);
        $this->assertCount(1, $tokens);
        $this->assertNotSame($first->id, $tokens->first()->id);
    }

    /**
     * The setup command must register the server at USER scope. `claude mcp add`
     * defaults to local scope, which ties the server to whichever folder the person
     * was standing in when they pasted it -- so it would quietly disappear the next
     * time they opened Claude Code anywhere else. Most holders of one of these keys
     * are HR or a manager with no notion of a project folder, and would read that as
     * the key having stopped working.
     */
    public function test_the_setup_command_registers_the_server_for_the_whole_machine(): void
    {
        $redirect = $this->actingInTenant()->from(route('app.screen', 'security'))
            ->post('/app/security/ai-key/generate', ['password' => 'password']);

        $command = $redirect->getSession()->get('aiKeyCommand');

        $this->assertStringContainsString('--scope user', $command);
        $this->assertStringContainsString('--transport http', $command);
        $this->assertStringContainsString('/mcp/amanahku', $command);
    }

    public function test_plaintext_shows_once_then_is_gone_on_next_load(): void
    {
        $securityUrl = route('app.screen', 'security');

        $redirect = $this->actingInTenant()->from($securityUrl)
            ->post('/app/security/ai-key/generate', ['password' => 'password']);
        $redirect->assertSessionHas('aiKeyPlaintext');
        $redirect->assertSessionHas('aiKeyCommand');
        $redirect->assertRedirect($securityUrl);

        $page = $this->actingInTenant()->get($securityUrl);
        $page->assertOk();
        $this->assertStringContainsString('Copy this key now', $page->getContent());

        // A later, separate page load no longer carries the flashed plaintext.
        $second = $this->actingInTenant()->get(route('app.screen', 'security'));
        $second->assertOk();
        $this->assertStringNotContainsString('Copy this key now', $second->getContent());
    }

    public function test_revoke_deletes_the_token(): void
    {
        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password']);
        $this->assertCount(1, $this->tokensFor($this->user));

        $response = $this->actingInTenant()->post('/app/security/ai-key/revoke');

        $response->assertRedirect();
        $this->assertCount(0, $this->tokensFor($this->user));
    }

    public function test_guest_cannot_generate_or_revoke(): void
    {
        $this->post('/app/security/ai-key/generate', ['password' => 'password'])->assertRedirect('/login');
        $this->post('/app/security/ai-key/revoke')->assertRedirect('/login');
    }
}
