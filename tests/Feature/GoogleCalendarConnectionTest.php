<?php

namespace Tests\Feature;

use App\Jobs\CalendarFullSyncJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_calendar.client_id' => 'client-123', 'services.google_calendar.client_secret' => 'secret-456']);

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

    public function test_redirect_stashes_state_and_sends_the_browser_to_google(): void
    {
        $response = $this->actingInTenant()->get(route('google-calendar.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
        $this->assertNotNull(session('google_calendar.state'));
    }

    public function test_connect_routes_404_when_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->actingInTenant()->get(route('google-calendar.redirect'))->assertNotFound();
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        $this->actingInTenant();
        session(['google_calendar.state' => 'expected-state']);

        $this->get(route('google-calendar.callback', ['state' => 'wrong-state', 'code' => 'abc']))
            ->assertRedirect()
            ->assertSessionHasErrors('google_calendar');

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_callback_stores_the_connection_against_only_the_authenticated_user(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            ]),
        ]);
        $this->actingInTenant();
        session(['google_calendar.state' => 'expected-state']);

        $this->get(route('google-calendar.callback', ['state' => 'expected-state', 'code' => 'auth-code']))
            ->assertRedirect(route('app.screen', 'board').'?calendar=connected');

        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $this->user->id]);
        $this->assertSame('access-1', GoogleCalendarConnection::where('user_id', $this->user->id)->first()->access_token);
    }

    public function test_disconnect_removes_the_authenticated_users_connection_only(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'a', 'refresh_token' => 'b', 'expires_at' => now()]);
        GoogleCalendarConnection::create(['user_id' => $other->id, 'access_token' => 'c', 'refresh_token' => 'd', 'expires_at' => now()]);

        $this->actingInTenant()->post(route('google-calendar.disconnect'))->assertRedirect(route('app.screen', 'board'));

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $other->id]);
    }

    public function test_reconnecting_clears_a_revoked_flag_and_starts_a_full_sync(): void
    {
        Queue::fake();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'r2', 'expires_in' => 3600])]);
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'old', 'refresh_token' => 'r1', 'expires_at' => now(), 'revoked_at' => now(), 'calendar_id' => 'cal-old', 'sync_token' => 'tok']);
        $this->actingInTenant();
        session(['google_calendar.state' => 's']);

        $this->get(route('google-calendar.callback', ['state' => 's', 'code' => 'c']));

        $connection = GoogleCalendarConnection::where('user_id', $this->user->id)->first();
        $this->assertNull($connection->revoked_at);
        $this->assertNull($connection->calendar_id, 'a reconnect may be a different Google account, find the calendar again');
        $this->assertNull($connection->sync_token);
        Queue::assertPushed(CalendarFullSyncJob::class, fn ($job) => $job->userId === $this->user->id);
    }
}
