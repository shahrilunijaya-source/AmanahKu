<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hand-rolled OpenID Connect relying-party (authorization-code flow). Built on
 * the Laravel HTTP client so it needs no external SSO package. Reads its config
 * from config('services.oidc'). The flow is two HTTP redirects:
 *
 *   1. redirectUrl()  — build the IdP /authorize URL (caller stashes state in session)
 *   2. exchangeCode() — POST the code to /token, then GET /userinfo with the access token
 *
 * SECURITY: this class only fetches identity claims. It never decides tenancy,
 * roles, or super-admin status — that policy lives in the controller, which
 * accepts only email-verified userinfo and never auto-attaches a tenant.
 */
class OidcClient
{
    /**
     * @param  array{client_id?:?string,client_secret?:?string,issuer?:?string,authorize_url?:?string,token_url?:?string,userinfo_url?:?string,redirect?:?string,scopes?:?string,allowed_domains?:?string,require_existing_user?:bool|string|null,label?:?string}  $config
     */
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('services.oidc', []));
    }

    /**
     * SSO is only usable when the client credentials and all three endpoints are
     * present. The login button and the routes both gate on this.
     */
    public function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'authorize_url', 'token_url', 'userinfo_url'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** A fresh, unguessable CSRF state value the caller stores in the session. */
    public function newState(): string
    {
        return Str::random(40);
    }

    /** Provider name for the login button, e.g. "SSO" or "Google". */
    public function label(): string
    {
        $label = trim((string) ($this->config['label'] ?? ''));

        return $label !== '' ? $label : 'SSO';
    }

    /**
     * Email domains permitted to sign in. Empty means no restriction.
     *
     * @return list<string>
     */
    public function allowedDomains(): array
    {
        $raw = (string) ($this->config['allowed_domains'] ?? '');

        return collect(explode(',', $raw))
            ->map(fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a verified email is allowed to sign in at all.
     *
     * This is the security gate for SSO. With a private IdP the allowlist can stay
     * empty, because the IdP itself decides who exists. With a public provider such
     * as Google it must not: every account on the provider would otherwise be able
     * to provision itself a user row here.
     */
    public function allowsEmail(string $email): bool
    {
        $allowed = $this->allowedDomains();

        if ($allowed === []) {
            return true;
        }

        return in_array(Str::lower(Str::afterLast(trim($email), '@')), $allowed, strict: true);
    }

    /**
     * Whether SSO may only sign in accounts that already exist.
     *
     * Defaults to true, the fail-safe reading. Turn it off only for a private IdP that
     * is itself the roster of who may hold an account. Against a public provider it must
     * stay on: a shared domain such as gmail.com makes allowsEmail() no barrier at all,
     * and this is then the only thing standing between a stranger and a new user row.
     */
    public function requiresExistingUser(): bool
    {
        return filter_var($this->config['require_existing_user'] ?? true, FILTER_VALIDATE_BOOL);
    }

    /** Build the IdP authorize URL for the given state. */
    public function redirectUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->config['scopes'] ?: 'openid email profile',
            'state' => $state,
        ];

        // Google reads `hd` to pre-filter its account chooser, so staff are not offered
        // their personal Gmail. It is a UX hint on a request parameter the caller can
        // strip, NOT a security control — allowsEmail() is the gate. Only meaningful
        // for a single domain; Google takes no list.
        $allowed = $this->allowedDomains();
        if (count($allowed) === 1) {
            $params['hd'] = $allowed[0];
        }

        return rtrim((string) $this->config['authorize_url'], '?').'?'.http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens, then resolve the userinfo claims.
     *
     * @return array<string,mixed> Raw userinfo claims from the IdP.
     *
     * @throws RuntimeException when the token exchange or userinfo call fails.
     */
    public function exchangeCode(string $code): array
    {
        $token = Http::asForm()->post((string) $this->config['token_url'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if (! $token->successful() || blank($token->json('access_token'))) {
            throw new RuntimeException('OIDC token exchange failed.');
        }

        $userinfo = Http::withToken((string) $token->json('access_token'))
            ->acceptJson()
            ->get((string) $this->config['userinfo_url']);

        if (! $userinfo->successful()) {
            throw new RuntimeException('OIDC userinfo request failed.');
        }

        return (array) $userinfo->json();
    }

    /**
     * Pull a verified email from userinfo claims, or null if absent/unverified.
     * The standard OIDC claim is email_verified; we accept boolean true or the
     * string "true" some providers emit. No verified email → no auto-login.
     */
    public function verifiedEmail(array $claims): ?string
    {
        $email = $claims['email'] ?? null;
        $verified = $claims['email_verified'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $isVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';

        return $isVerified ? Str::lower(trim($email)) : null;
    }

    private function redirectUri(): string
    {
        return $this->config['redirect'] ?: route('oidc.callback');
    }
}
