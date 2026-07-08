<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enterprise single sign-on via OpenID Connect (authorization-code flow).
 *
 * SECURITY MODEL — deliberately minimal trust in the IdP:
 *  - We accept ONLY an email-verified claim from userinfo (see verifiedEmail()).
 *  - A matching existing user is logged in as-is.
 *  - A brand-new email creates a user with NO tenant and NO roles — they land on
 *    the "no workspace yet" screen until an HR admin invites them by email.
 *  - We NEVER set is_super_admin, NEVER auto-attach to a tenant, and NEVER grant
 *    a privileged role. SSO can create an account; it cannot grant access.
 *  - state is verified against the session to defeat CSRF / replay.
 */
class OidcController extends Controller
{
    private const STATE_KEY = 'oidc.state';

    public function __construct(private OidcClient $oidc)
    {
        // When SSO isn't configured the whole flow is off — return 404, not a 500.
        abort_unless($this->oidc->configured(), 404);
    }

    /** Step 1: stash a fresh state and bounce the browser to the IdP. */
    public function redirect(Request $request): RedirectResponse
    {
        $state = $this->oidc->newState();
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->oidc->redirectUrl($state));
    }

    /** Step 2: verify state, exchange the code, resolve identity, sign in. */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $returned = (string) $request->query('state', '');

        // Constant-time compare; reject if missing or mismatched (CSRF / replay).
        if (blank($expected) || ! hash_equals((string) $expected, $returned)) {
            return redirect('/login')->withErrors(['email' => 'SSO sign-in could not be verified. Please try again.']);
        }

        $code = (string) $request->query('code', '');
        if (blank($code)) {
            return redirect('/login')->withErrors(['email' => 'SSO sign-in was cancelled or failed.']);
        }

        try {
            $claims = $this->oidc->exchangeCode($code);
        } catch (Throwable $e) {
            report($e);

            return redirect('/login')->withErrors(['email' => 'SSO provider could not be reached. Please try again.']);
        }

        $email = $this->oidc->verifiedEmail($claims);
        if ($email === null) {
            return redirect('/login')->withErrors(['email' => 'Your identity provider did not return a verified email address.']);
        }

        $user = $this->resolveUser($email, $claims);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Existing members land on workspace select; brand-new accounts see the
        // "no workspace yet" empty state there until an HR admin invites them.
        return redirect()->route('tenant.select');
    }

    /**
     * Match a verified email to an existing user, or provision a tenant-less one.
     * New accounts get a verified email (the IdP vouched for it) and a random
     * unusable-by-design password; they sign in only via SSO until they reset.
     */
    private function resolveUser(string $email, array $claims): User
    {
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        $name = $claims['name'] ?? $claims['preferred_username'] ?? Str::before($email, '@');

        // forceCreate so we control exactly which columns are set. is_super_admin
        // is intentionally never touched here — it defaults to false in the DB.
        return User::forceCreate([
            'name' => (string) $name,
            'email' => $email,
            'password' => bcrypt(Str::random(48)),
            'email_verified_at' => now(),
        ]);
    }
}
