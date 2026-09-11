<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Ports\Data\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hand-rolled Google OAuth + Calendar v3 client, same shape as OidcClient (no
 * external SDK). CR-01: writes to and reads from the person's dedicated "Amanahku"
 * calendar (never the primary), created on first use and remembered on the connection.
 * Only GoogleCalendarAdapter calls this; feature code goes through CalendarPort.
 */
class GoogleCalendarClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    /** Full calendar scope: creating the dedicated calendar needs more than calendar.events. */
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    public const CALENDAR_NAME = 'Amanahku';

    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    /** Recurring series are expanded one occurrence at a time, this far ahead (rule 5). */
    public const HORIZON_DAYS = 30;

    /** @param array{client_id?:?string,client_secret?:?string,redirect?:?string} $config */
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('services.google_calendar', []));
    }

    public function configured(): bool
    {
        foreach (['client_id', 'client_secret'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    public function redirectUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPE,
            // offline + consent: without both, Google only returns a refresh_token
            // on the account's very first-ever authorization for this app.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     *
     * @throws RuntimeException
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if (! $response->successful() || blank($response->json('access_token')) || blank($response->json('refresh_token'))) {
            throw new RuntimeException('Google Calendar token exchange failed.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => (string) $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    /** Returns a valid access token, refreshing and persisting it first if expired. */
    public function accessTokenFor(GoogleCalendarConnection $connection): string
    {
        if ($connection->expires_at !== null && $connection->expires_at->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException('Google Calendar token refresh failed.');
        }

        $connection->update([
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $connection->access_token;
    }

    /**
     * The person's dedicated Amanahku calendar id, creating it the first time. A
     * reconnect finds the calendar by name instead of making a second one (rule 2).
     */
    public function ensureCalendar(GoogleCalendarConnection $connection): string
    {
        if ($connection->calendar_id) {
            return $connection->calendar_id;
        }

        $token = $this->accessTokenFor($connection);
        $list = Http::withToken($token)->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'owner']);
        $this->guard($list, 'calendar list');
        $existing = collect($list->json('items', []))->first(fn ($c) => ($c['summary'] ?? null) === self::CALENDAR_NAME);

        if ($existing) {
            $id = (string) $existing['id'];
        } else {
            $made = Http::withToken($token)->post(self::API.'/calendars', ['summary' => self::CALENDAR_NAME, 'timeZone' => self::TIMEZONE]);
            $this->guard($made, 'calendar create');
            $id = (string) $made->json('id');
        }

        $connection->forceFill(['calendar_id' => $id])->save();

        return $id;
    }

    /**
     * Create or update an event in the Amanahku calendar. Google's all-day events use an
     * EXCLUSIVE end date. A dead external id (deleted client-side) falls back to create.
     *
     * @return array{0: string, 1: ?string} the event id and Google's `updated` stamp
     */
    public function upsertEvent(CalendarEvent $event, GoogleCalendarConnection $connection): array
    {
        $token = $this->accessTokenFor($connection);
        $calendar = $this->ensureCalendar($connection);
        $events = self::API.'/calendars/'.rawurlencode($calendar).'/events';

        $payload = [
            'summary' => $event->title,
            'description' => $event->description,
            'start' => $event->allDay
                ? ['date' => $event->startsAt->toDateString()]
                : ['dateTime' => $event->startsAt->toIso8601String(), 'timeZone' => self::TIMEZONE],
            'end' => $event->allDay
                ? ['date' => $event->endsAt->toDateString()]
                : ['dateTime' => $event->endsAt->toIso8601String(), 'timeZone' => self::TIMEZONE],
        ];

        $response = $event->externalId
            ? Http::withToken($token)->patch($events.'/'.rawurlencode($event->externalId), $payload)
            : Http::withToken($token)->post($events, $payload);

        if ($event->externalId && in_array($response->status(), [404, 410], true)) {
            $response = Http::withToken($token)->post($events, $payload);
        }

        $this->guard($response, 'event sync');

        return [(string) $response->json('id'), $response->json('updated')];
    }

    /** Google returns 404/410 for an event already gone client-side; treat as success. */
    public function deleteEvent(string $eventId, GoogleCalendarConnection $connection): void
    {
        $token = $this->accessTokenFor($connection);
        $calendar = $this->ensureCalendar($connection);
        $response = Http::withToken($token)->delete(self::API.'/calendars/'.rawurlencode($calendar).'/events/'.rawurlencode($eventId));

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Google Calendar event delete failed: '.$response->body());
        }
    }

    /**
     * Everything that changed in the Amanahku calendar since the last pull. Incremental
     * via the sync token kept on the connection; a 410 (token expired) or a first pull
     * lists from `since` up to the horizon, recurring series expanded per occurrence.
     *
     * @return list<CalendarEvent>
     */
    public function listChanges(GoogleCalendarConnection $connection, CarbonImmutable $since): array
    {
        $token = $this->accessTokenFor($connection);
        $calendar = $this->ensureCalendar($connection);
        $url = self::API.'/calendars/'.rawurlencode($calendar).'/events';

        $query = ['singleEvents' => 'true', 'showDeleted' => 'true', 'maxResults' => 250];
        $query += $connection->sync_token
            ? ['syncToken' => $connection->sync_token]
            : ['updatedMin' => $since->toIso8601String(), 'timeMax' => now()->addDays(self::HORIZON_DAYS)->toIso8601String()];

        $out = [];
        $pageToken = null;
        do {
            $response = Http::withToken($token)->get($url, $query + ($pageToken ? ['pageToken' => $pageToken] : []));
            if ($response->status() === 410 && $connection->sync_token) {
                $connection->forceFill(['sync_token' => null])->save();

                return $this->listChanges($connection, $since);
            }
            $this->guard($response, 'event list');

            foreach ($response->json('items', []) as $item) {
                $out[] = $this->toEvent($item);
            }
            $pageToken = $response->json('nextPageToken');
            if ($next = $response->json('nextSyncToken')) {
                $connection->forceFill(['sync_token' => (string) $next])->save();
            }
        } while ($pageToken);

        return $out;
    }

    /** @param array<string, mixed> $item */
    private function toEvent(array $item): CalendarEvent
    {
        $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
        $end = $item['end']['dateTime'] ?? $item['end']['date'] ?? $start;
        $allDay = isset($item['start']['date']);
        $startsAt = $start ? CarbonImmutable::parse($start, self::TIMEZONE) : CarbonImmutable::now(self::TIMEZONE);

        return new CalendarEvent(
            title: (string) ($item['summary'] ?? '(no title)'),
            startsAt: $startsAt,
            endsAt: $end ? CarbonImmutable::parse($end, self::TIMEZONE) : $startsAt->addHour(),
            description: $item['description'] ?? null,
            externalId: (string) ($item['id'] ?? ''),
            allDay: $allDay,
            cancelled: ($item['status'] ?? '') === 'cancelled',
            version: $item['updated'] ?? null,
        );
    }

    private function guard(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new RuntimeException("Google Calendar {$what} failed: ".$response->body());
        }
    }

    private function redirectUri(): string
    {
        return $this->config['redirect'] ?: route('google-calendar.callback');
    }
}
