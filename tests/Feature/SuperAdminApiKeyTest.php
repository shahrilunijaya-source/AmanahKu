<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers issuing and revoking machine API keys from the super-admin console.
 */
class SuperAdminApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
    }

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function ordinaryUser(): User
    {
        $u = User::create(['name' => 'Joe', 'email' => 'joe@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $u;
    }

    public function test_an_ordinary_user_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->ordinaryUser())
            ->get(route('superadmin.companies.api-keys', $this->tenant))
            ->assertStatus(403);
    }

    public function test_creating_shows_the_key_once_and_never_again(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post(route('superadmin.companies.api-keys.store', $this->tenant), [
            'name' => 'Track',
            'scopes' => ['projects:read'],
        ]);

        $plain = session('newKey');
        $this->assertIsString($plain);
        $this->assertNotEmpty($plain);
        $response->assertRedirect();

        // The redirect-follow render — the one time the flashed key is shown.
        $this->actingAs($admin)
            ->get(route('superadmin.companies.api-keys', $this->tenant))
            ->assertOk()
            ->assertSee($plain);

        // A second render must not carry it — only the sha256 hash is stored, so
        // there is nothing to show a second time even if the screen wanted to.
        $this->actingAs($admin)
            ->get(route('superadmin.companies.api-keys', $this->tenant))
            ->assertOk()
            ->assertDontSee($plain);
    }

    public function test_a_created_key_works_and_stops_working_once_revoked(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('superadmin.companies.api-keys.store', $this->tenant), [
            'name' => 'Track',
            'scopes' => ['projects:read'],
        ]);

        $plain = session('newKey');

        // actingAs() leaves the web guard authenticated in-memory for the rest of the
        // test; Sanctum checks config('sanctum.guard') = ['web'] before ever parsing a
        // bearer token, so without this the lingering super-admin session — not the
        // key — would answer the request. ApiTokenTest avoids the clash entirely by
        // never mixing actingAs with bearer calls; here both are unavoidable.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects')->assertOk();

        $token = PersonalAccessToken::firstOrFail();

        $this->actingAs($admin)
            ->post(route('superadmin.companies.api-keys.revoke', [$this->tenant, $token]))
            ->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects')->assertStatus(401);
    }

    public function test_a_token_belonging_to_another_company_cannot_be_revoked_through_this_one(): void
    {
        // Route-model binding in this app resolves across every tenant, so the bound
        // token here is another company's. The controller has to refuse it itself.
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        $client = ApiClient::create(['tenant_id' => $other->id, 'name' => 'Other']);
        $client->mintKey(['projects:read']);

        $token = PersonalAccessToken::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.companies.api-keys.revoke', [$this->tenant, $token]))
            ->assertStatus(404);

        $this->assertSame(1, PersonalAccessToken::count());
    }
}
