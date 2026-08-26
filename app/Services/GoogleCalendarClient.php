<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hand-rolled Google OAuth + Calendar v3 client, same shape as OidcClient (no
 * external SDK). One-way sync only: this class only ever writes to Google
 * Calendar, never reads events back.
 */
class GoogleCalendarClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

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
     * Create or update the all-day event for a work item's due date. Google's
     * all-day events use an EXCLUSIVE end date, so end.date is due_at + 1 day.
     */
    public function createOrUpdateEvent(WorkItem $item, GoogleCalendarConnection $connection): string
    {
        $token = $this->accessTokenFor($connection);

        $payload = [
            'summary' => $item->title,
            'description' => route('work.show', $item),
            'start' => ['date' => $item->due_at->toDateString()],
            'end' => ['date' => $item->due_at->copy()->addDay()->toDateString()],
        ];

        $response = $item->google_event_id
            ? Http::withToken($token)->patch(self::EVENTS_URL.'/'.$item->google_event_id, $payload)
            : Http::withToken($token)->post(self::EVENTS_URL, $payload);

        // The user may have deleted the event directly in their own Google Calendar —
        // a 404/410 on PATCH means the id is dead, not that sync should fail forever.
        // Fall back to creating a fresh event instead of throwing.
        if ($item->google_event_id && ! $response->successful() && in_array($response->status(), [404, 410], true)) {
            $response = Http::withToken($token)->post(self::EVENTS_URL, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar event sync failed: '.$response->body());
        }

        return (string) $response->json('id');
    }

    /** Google returns 404/410 for an event already gone client-side; treat as success. */
    public function deleteEvent(string $eventId, GoogleCalendarConnection $connection): void
    {
        $token = $this->accessTokenFor($connection);
        $response = Http::withToken($token)->delete(self::EVENTS_URL.'/'.$eventId);

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Google Calendar event delete failed: '.$response->body());
        }
    }

    private function redirectUri(): string
    {
        return $this->config['redirect'] ?: route('google-calendar.callback');
    }
}
