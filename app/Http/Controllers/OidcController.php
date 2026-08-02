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
 *  - The email must pass the configured domain allowlist, if one is set.
 *  - A matching existing user is logged in as-is.
 *  - A brand-new email is REFUSED by default (requiresExistingUser). Only a
 *    deployment that opts out provisions a user with NO tenant and NO roles,
 *    who lands on the "no workspace yet" screen until HR invites them.
 *  - We NEVER set is_super_admin, NEVER auto-attach to a tenant, and NEVER grant
 *    a privileged role. SSO can at most create an account; it cannot grant access.
 *  - state is verified against the session to defeat CSRF / replay.
 *
 * The two gates matter most against a PUBLIC provider. With staff on personal
 * Gmail addresses the domain allowlist is worthless — everybody shares gmail.com —
 * so requiresExistingUser is the only thing keeping strangers out.
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

        // Checked before resolveUser so a rejected address never reaches the database.
        if (! $this->oidc->allowsEmail($email)) {
            return redirect('/login')->withErrors([
                'email' => 'That account is not permitted to sign in here. Use your '
                    .implode(' or ', $this->oidc->allowedDomains()).' account.',
            ]);
        }

        $user = $this->resolveUser($email, $claims);

        if ($user === null) {
            return redirect('/login')->withErrors([
                'email' => 'No Amanahku account uses that email address. Ask your HR admin to add you first.',
            ]);
        }
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
     *
     * Returns null when provisioning is off and no account matches — the caller
     * turns that into a "ask HR to add you" refusal.
     */
    private function resolveUser(string $email, array $claims): ?User
    {
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        if ($this->oidc->requiresExistingUser()) {
            return null;
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
