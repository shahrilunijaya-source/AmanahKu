<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Personal "connect your Google Calendar" flow — separate from OidcController's
 * SSO login. SECURITY: the callback validates `state` against the session and
 * always writes the row against the authenticated user (auth()->id()), never
 * anything from the request — see the spec's connect-callback rule.
 */
class GoogleCalendarConnectionController extends Controller
{
    private const STATE_KEY = 'google_calendar.state';

    public function __construct(private GoogleCalendarClient $client)
    {
        abort_unless($this->client->configured(), 404);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $state = $this->client->newState();
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->client->redirectUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $returned = (string) $request->query('state', '');

        if (blank($expected) || ! hash_equals((string) $expected, $returned)) {
            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google Calendar connection could not be verified. Please try again.',
            ]);
        }

        $code = (string) $request->query('code', '');
        if (blank($code)) {
            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google Calendar connection was cancelled.',
            ]);
        }

        try {
            $tokens = $this->client->exchangeCode($code);
        } catch (Throwable $e) {
            report($e);

            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google could not be reached. Please try again.',
            ]);
        }

        GoogleCalendarConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds($tokens['expires_in']),
            ]
        );

        return redirect('/app/profile')->with('ok', 'Google Calendar connected.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        GoogleCalendarConnection::where('user_id', $request->user()->id)->delete();

        return redirect('/app/profile')->with('ok', 'Google Calendar disconnected.');
    }
}
