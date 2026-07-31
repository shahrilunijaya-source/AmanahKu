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
     * @param  array{client_id?:?string,client_secret?:?string,issuer?:?string,authorize_url?:?string,token_url?:?string,userinfo_url?:?string,redirect?:?string,scopes?:?string}  $config
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

    /** Build the IdP authorize URL for the given state. */
    public function redirectUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->config['scopes'] ?: 'openid email profile',
            'state' => $state,
        ]);

        return rtrim((string) $this->config['authorize_url'], '?').'?'.$query;
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
