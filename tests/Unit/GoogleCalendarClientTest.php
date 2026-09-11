<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(): GoogleCalendarClient
    {
        return new GoogleCalendarClient([
            'client_id' => 'client-123',
            'client_secret' => 'secret-456',
            'redirect' => 'https://example.test/callback',
        ]);
    }

    private function connection(array $overrides = []): GoogleCalendarConnection
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        return GoogleCalendarConnection::create(array_merge([
            'user_id' => $user->id,
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    public function test_configured_requires_client_id_and_secret(): void
    {
        $this->assertTrue($this->client()->configured());
        $this->assertFalse((new GoogleCalendarClient([]))->configured());
    }

    public function test_redirect_url_requests_offline_access_and_the_calendar_scope(): void
    {
        $url = $this->client()->redirectUrl('state-abc');

        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/calendar'), $url);
        $this->assertStringContainsString('state=state-abc', $url);
    }

    public function test_exchange_code_returns_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600,
            ]),
        ]);

        $tokens = $this->client()->exchangeCode('auth-code');

        $this->assertSame('new-access', $tokens['access_token']);
        $this->assertSame('new-refresh', $tokens['refresh_token']);
        $this->assertSame(3600, $tokens['expires_in']);
    }

    public function test_exchange_code_throws_on_failure(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(\RuntimeException::class);
        $this->client()->exchangeCode('bad-code');
    }

    public function test_access_token_for_reuses_unexpired_token_without_a_network_call(): void
    {
        Http::fake();
        $connection = $this->connection(['expires_at' => now()->addMinutes(30)]);

        $token = $this->client()->accessTokenFor($connection);

        $this->assertSame('valid-token', $token);
        Http::assertNothingSent();
    }

    public function test_access_token_for_refreshes_and_persists_when_expired(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-token', 'expires_in' => 3600]),
        ]);
        $connection = $this->connection(['expires_at' => now()->subMinute()]);

        $token = $this->client()->accessTokenFor($connection);

        $this->assertSame('refreshed-token', $token);
        $this->assertSame('refreshed-token', $connection->fresh()->access_token);
    }

    /** The upsert, pull and dedicated-calendar paths live in tests/Feature/GoogleCalendarClientTest (CR-01). */
    public function test_delete_event_treats_404_as_success(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(null, 404)]);
        $connection = $this->connection(['calendar_id' => 'cal-1']);

        $this->client()->deleteEvent('evt_gone', $connection);
        $this->assertTrue(true); // no exception
    }

    public function test_delete_event_throws_on_real_failure(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'server_error'], 500)]);
        $connection = $this->connection(['calendar_id' => 'cal-1']);

        $this->expectException(\RuntimeException::class);
        $this->client()->deleteEvent('evt_x', $connection);
    }
}
