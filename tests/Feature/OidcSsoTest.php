<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OidcSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.oidc', [
            'client_id' => 'amanahku-client',
            'client_secret' => 'shhh-secret',
            'issuer' => 'https://idp.example.com',
            'authorize_url' => 'https://idp.example.com/authorize',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
            'redirect' => 'https://app.test/auth/oidc/callback',
            'scopes' => 'openid email profile',
            // Most cases here model a private IdP that is trusted to provision. The
            // public-provider default is the opposite, and has its own cases below.
            'require_existing_user' => false,
        ]);
    }

    /** Fake the IdP token + userinfo endpoints with the given verified email. */
    private function fakeIdp(string $email, mixed $verified = true, ?string $name = null): void
    {
        Http::fake([
            'https://idp.example.com/token' => Http::response([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://idp.example.com/userinfo' => Http::response(array_filter([
                'email' => $email,
                'email_verified' => $verified,
                'name' => $name,
            ], fn ($v) => $v !== null)),
        ]);
    }

    public function test_sso_button_is_shown_when_oidc_is_configured(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in with SSO');
    }

    public function test_sso_button_is_hidden_when_oidc_is_not_configured(): void
    {
        config()->set('services.oidc', []);

        $this->get('/login')->assertOk()->assertDontSee('Sign in with SSO');
    }

    public function test_routes_404_when_oidc_not_configured(): void
    {
        config()->set('services.oidc', []);

        $this->get('/auth/oidc/redirect')->assertNotFound();
    }

    public function test_redirect_stashes_state_and_bounces_to_idp(): void
    {
        $response = $this->get('/auth/oidc/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://idp.example.com/authorize?', $response->headers->get('Location'));
        $this->assertNotNull(session('oidc.state'));
        $this->assertStringContainsString('state='.session('oidc.state'), $response->headers->get('Location'));
    }

    public function test_callback_logs_in_an_existing_user(): void
    {
        $user = User::create([
            'name' => 'Existing', 'email' => 'existing@corp.com', 'password' => Hash::make('password'),
        ]);
        $tenant = Tenant::create(['slug' => 'corp', 'name' => 'Corp', 'initials' => 'CO']);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);

        $this->fakeIdp('existing@corp.com');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $this->assertAuthenticatedAs($user);
        // No tenant was added or removed by SSO.
        $this->assertSame(1, $user->fresh()->tenants()->count());
    }

    public function test_callback_creates_a_tenantless_user_for_a_new_email(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'newbie@corp.com']);

        $this->fakeIdp('newbie@corp.com', true, 'New Bie');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $created = User::where('email', 'newbie@corp.com')->first();
        $this->assertNotNull($created);
        $this->assertAuthenticatedAs($created);
        // SECURITY: no tenant, no privileged role, not a super-admin.
        $this->assertSame(0, $created->tenants()->count());
        $this->assertFalse($created->isSuperAdmin());
        $this->assertNotNull($created->email_verified_at);
    }

    public function test_callback_rejects_a_state_mismatch(): void
    {
        $this->fakeIdp('attacker@corp.com');

        $this->withSession(['oidc.state' => 'real-state'])
            ->get('/auth/oidc/callback?code=abc&state=forged-state')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'attacker@corp.com']);
        // The IdP must never even be contacted on a bad state.
        Http::assertNothingSent();
    }

    public function test_callback_rejects_an_unverified_email(): void
    {
        $this->fakeIdp('unverified@corp.com', false);

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@corp.com']);
    }

    public function test_callback_handles_a_failed_token_exchange(): void
    {
        Http::fake([
            'https://idp.example.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=bad&state=good-state')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** Narrow the allowlist to the given comma-separated domains. */
    private function allowDomains(string $domains): void
    {
        config()->set('services.oidc.allowed_domains', $domains);
    }

    public function test_callback_admits_an_email_on_an_allowed_domain(): void
    {
        $this->allowDomains('corp.com');
        $this->fakeIdp('staff@corp.com', true, 'On Staff');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $this->assertAuthenticatedAs(User::where('email', 'staff@corp.com')->first());
    }

    public function test_callback_rejects_an_email_outside_the_allowlist_without_creating_a_user(): void
    {
        $this->allowDomains('corp.com');
        $this->fakeIdp('outsider@gmail.com', true, 'Out Sider');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        // The point of the allowlist: a rejected address leaves nothing behind.
        $this->assertDatabaseMissing('users', ['email' => 'outsider@gmail.com']);
    }

    public function test_allowlist_accepts_several_domains_and_ignores_case_and_spacing(): void
    {
        $this->allowDomains(' Corp.com , subsidiary.com ');
        $this->fakeIdp('Staff@SUBSIDIARY.com', true, 'Sub Staff');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $this->assertAuthenticatedAs(User::where('email', 'staff@subsidiary.com')->first());
    }

    public function test_an_empty_allowlist_admits_any_verified_email(): void
    {
        // The pre-existing generic-IdP behaviour must survive the allowlist landing.
        $this->fakeIdp('anyone@wherever.example', true, 'Any One');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $this->assertDatabaseHas('users', ['email' => 'anyone@wherever.example']);
    }

    public function test_redirect_sends_the_google_hd_hint_for_a_single_allowed_domain(): void
    {
        $this->allowDomains('corp.com');

        $location = (string) $this->get('/auth/oidc/redirect')->headers->get('Location');

        $this->assertStringContainsString('hd=corp.com', $location);
    }

    public function test_redirect_omits_hd_when_the_allowlist_is_empty_or_has_several_domains(): void
    {
        $this->assertStringNotContainsString('hd=', (string) $this->get('/auth/oidc/redirect')->headers->get('Location'));

        $this->allowDomains('corp.com,subsidiary.com');

        // Google takes a single domain, so a list must not be squeezed into the hint.
        $this->assertStringNotContainsString('hd=', (string) $this->get('/auth/oidc/redirect')->headers->get('Location'));
    }

    public function test_login_button_carries_the_configured_provider_label(): void
    {
        config()->set('services.oidc.label', 'Google');

        $this->get('/login')->assertOk()->assertSee('Sign in with Google');
    }

    public function test_provisioning_is_off_unless_a_deployment_opts_in(): void
    {
        // Fail-safe default. A deployment must say so explicitly to let SSO create accounts.
        config()->offsetUnset('services.oidc.require_existing_user');

        $this->assertTrue(OidcClient::fromConfig()->requiresExistingUser());
    }

    public function test_callback_refuses_an_unknown_email_when_provisioning_is_off(): void
    {
        config()->set('services.oidc.require_existing_user', true);
        $this->fakeIdp('stranger.unijaya@gmail.com', true, 'A Stranger');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        // The whole point: staff on shared gmail.com means the domain proves nothing,
        // so an unknown address must leave no trace behind.
        $this->assertDatabaseMissing('users', ['email' => 'stranger.unijaya@gmail.com']);
    }

    public function test_callback_still_signs_in_a_known_user_when_provisioning_is_off(): void
    {
        config()->set('services.oidc.require_existing_user', true);
        $user = User::create([
            'name' => 'Aina', 'email' => 'aina.unijaya@gmail.com', 'password' => Hash::make('password'),
        ]);

        $this->fakeIdp('aina.unijaya@gmail.com');

        $this->withSession(['oidc.state' => 'good-state'])
            ->get('/auth/oidc/callback?code=abc&state=good-state')
            ->assertRedirect(route('tenant.select'));

        $this->assertAuthenticatedAs($user);
    }
}
