<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Ports\Data\CalendarEvent;
use App\Services\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The real Google leg (CR-01), against a faked Google: dedicated calendar, upsert, incremental pull. */
class GoogleCalendarClientTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://www.googleapis.com/calendar/v3';

    private GoogleCalendarConnection $connection;

    private GoogleCalendarClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->connection = GoogleCalendarConnection::create(['user_id' => $user->id, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->addHour()]);
        $this->client = new GoogleCalendarClient(['client_id' => 'id', 'client_secret' => 'secret']);
    }

    public function test_the_amanahku_calendar_is_created_once_and_remembered(): void
    {
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => 'primary', 'summary' => 'Demo']]]),
            self::API.'/calendars' => Http::response(['id' => 'cal-amanahku']),
        ]);

        $this->assertSame('cal-amanahku', $this->client->ensureCalendar($this->connection));
        $this->assertSame('cal-amanahku', $this->connection->fresh()->calendar_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calendars') && $r['summary'] === 'Amanahku' && $r['timeZone'] === 'Asia/Kuala_Lumpur');

        $this->client->ensureCalendar($this->connection->fresh());
        Http::assertSentCount(2);
    }

    public function test_reconnecting_finds_the_existing_amanahku_calendar_instead_of_making_another(): void
    {
        Http::fake([self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => 'cal-old', 'summary' => 'Amanahku']]])]);

        $this->assertSame('cal-old', $this->client->ensureCalendar($this->connection));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_upsert_writes_to_the_amanahku_calendar_never_the_primary(): void
    {
        $this->connection->forceFill(['calendar_id' => 'cal-1'])->save();
        Http::fake([self::API.'/calendars/cal-1/events' => Http::response(['id' => 'evt-1', 'updated' => '2026-10-01T02:00:00.000Z'])]);

        [$id, $version] = $this->client->upsertEvent(
            new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), description: 'Type: Task', allDay: true),
            $this->connection,
        );

        $this->assertSame('evt-1', $id);
        $this->assertSame('2026-10-01T02:00:00.000Z', $version);
        Http::assertSent(fn ($r) => $r['start'] === ['date' => '2026-10-05'] && $r['end'] === ['date' => '2026-10-06'] && $r['summary'] === 'Write the spec');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'calendars/primary'));
    }

    public function test_a_dead_event_id_is_recreated_rather_than_failing_forever(): void
    {
        $this->connection->forceFill(['calendar_id' => 'cal-1'])->save();
        Http::fake([
            self::API.'/calendars/cal-1/events/evt-gone' => Http::response(null, 404),
            self::API.'/calendars/cal-1/events' => Http::response(['id' => 'evt-2', 'updated' => 'u2']),
        ]);

        [$id] = $this->client->upsertEvent(new CalendarEvent('X', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: 'evt-gone', allDay: true), $this->connection);

        $this->assertSame('evt-2', $id);
    }

    public function test_pull_expands_recurrences_keeps_the_sync_token_and_maps_cancelled(): void
    {
        $this->connection->forceFill(['calendar_id' => 'cal-1'])->save();
        Http::fake([self::API.'/calendars/cal-1/events*' => Http::response([
            'items' => [
                ['id' => 'a', 'summary' => 'Standup', 'status' => 'confirmed', 'updated' => 'u1', 'start' => ['dateTime' => '2026-10-07T10:00:00+08:00'], 'end' => ['dateTime' => '2026-10-07T10:30:00+08:00'], 'recurringEventId' => 'series'],
                ['id' => 'b', 'status' => 'cancelled', 'updated' => 'u2'],
            ],
            'nextSyncToken' => 'sync-1',
        ])]);

        $changes = $this->client->listChanges($this->connection, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(2, $changes);
        $this->assertSame('Standup', $changes[0]->title);
        $this->assertSame('2026-10-07 10:00', $changes[0]->startsAt->format('Y-m-d H:i'));
        $this->assertFalse($changes[0]->allDay);
        $this->assertTrue($changes[1]->cancelled);
        $this->assertSame('sync-1', $this->connection->fresh()->sync_token);
        Http::assertSent(fn ($r) => $r['singleEvents'] === 'true' && $r['showDeleted'] === 'true' && isset($r['updatedMin']) && isset($r['timeMax']));

        $this->client->listChanges($this->connection->fresh(), CarbonImmutable::parse('2026-09-01'));
        Http::assertSent(fn ($r) => ($r['syncToken'] ?? null) === 'sync-1' && ! isset($r['updatedMin']));
    }

    public function test_an_expired_sync_token_falls_back_to_a_full_pull(): void
    {
        $this->connection->forceFill(['calendar_id' => 'cal-1', 'sync_token' => 'stale'])->save();
        Http::fake([self::API.'/calendars/cal-1/events*' => Http::sequence()
            ->push(null, 410)
            ->push(['items' => [], 'nextSyncToken' => 'fresh'])]);

        $this->client->listChanges($this->connection, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('fresh', $this->connection->fresh()->sync_token);
        Http::assertSentCount(2);
    }
}
