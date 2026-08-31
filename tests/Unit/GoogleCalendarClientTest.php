<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
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

    private function workItem(): WorkItem
    {
        $user = User::create(['name' => 'Assignee', 'email' => 'assignee@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Assignee', 'status' => 'active', 'workload' => 'green',
        ]);

        return $employee->workItems()->create([
            'tenant_id' => $tenant->id, 'title' => 'Ship the report', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-09-30',
        ]);
    }

    public function test_configured_requires_client_id_and_secret(): void
    {
        $this->assertTrue($this->client()->configured());
        $this->assertFalse((new GoogleCalendarClient([]))->configured());
    }

    public function test_redirect_url_requests_offline_access_and_the_calendar_events_scope(): void
    {
        $url = $this->client()->redirectUrl('state-abc');

        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/calendar.events'), $url);
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

    public function test_create_or_update_event_posts_a_new_event_with_exclusive_end_date(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new']),
        ]);
        $connection = $this->connection();
        $item = $this->workItem();

        $eventId = $this->client()->createOrUpdateEvent($item, $connection);

        $this->assertSame('evt_new', $eventId);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['start']['date'] === '2026-09-30'
                && $request['end']['date'] === '2026-10-01';
        });
    }

    public function test_create_or_update_event_patches_when_a_google_event_id_already_exists(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_existing'])]);
        $connection = $this->connection();
        $item = $this->workItem();
        $item->update(['google_event_id' => 'evt_existing']);

        $this->client()->createOrUpdateEvent($item, $connection);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains((string) $request->url(), 'evt_existing'));
    }

    public function test_create_or_update_event_falls_back_to_post_when_the_patch_target_is_gone(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/evt_gone' => Http::response(null, 404),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_new']),
        ]);
        $connection = $this->connection();
        $item = $this->workItem();
        $item->update(['google_event_id' => 'evt_gone']);

        $eventId = $this->client()->createOrUpdateEvent($item, $connection);

        $this->assertSame('evt_new', $eventId);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && ! str_contains((string) $request->url(), 'evt_gone'));
    }

    public function test_delete_event_treats_404_as_success(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(null, 404)]);
        $connection = $this->connection();

        $this->client()->deleteEvent('evt_gone', $connection);
        $this->assertTrue(true); // no exception
    }

    public function test_delete_event_throws_on_real_failure(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'server_error'], 500)]);
        $connection = $this->connection();

        $this->expectException(\RuntimeException::class);
        $this->client()->deleteEvent('evt_x', $connection);
    }
}
