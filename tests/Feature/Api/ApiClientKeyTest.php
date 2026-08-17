<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for machine API keys: a key belongs to an app, not to a person, and
 * carries an explicit list of scopes rather than inheriting a staff member's role.
 */
class ApiClientKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
    }

    public function test_minting_stamps_the_clients_tenant_on_the_token(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);

        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // findToken() takes the whole "{id}|{secret}" string and splits it itself.
        $token = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($token);
        $this->assertSame($this->tenant->id, $token->tenant_id);
        $this->assertSame(ApiClient::class, $token->tokenable_type);
        $this->assertSame(['projects:read'], $token->abilities);
    }

    public function test_deleting_a_client_deletes_its_keys(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $client->mintKey(['projects:read']);

        $this->assertSame(1, PersonalAccessToken::count());

        $client->delete();

        $this->assertSame(0, PersonalAccessToken::count());
    }
}
