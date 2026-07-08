<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
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
}
