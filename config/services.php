<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Amanahku workforce assistant. Driver "canned" (default) summarises live data with
    | no external calls. Set AMANAHKU_AI_DRIVER=claude + ANTHROPIC_API_KEY to go live.
    */
    'ai' => [
        'driver' => env('AMANAHKU_AI_DRIVER', 'canned'),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    /*
    | Enterprise single sign-on via OpenID Connect (authorization-code flow).
    | SSO is considered "configured" only when client_id, client_secret, and the
    | authorize/token/userinfo endpoints are all present. When unset, the SSO
    | button is hidden and the routes return 404 — auth falls back to password.
    | Hand-rolled (no Socialite dependency): see App\Services\OidcClient.
    |
    | Point authorize/token/userinfo at Google to get "Sign in with Google" —
    | no provider-specific code is involved.
    |
    | Two independent gates matter with a PUBLIC provider such as Google, which
    | vouches for every account on earth, not just your staff:
    |  - require_existing_user (default true): SSO signs in accounts that already
    |    exist and never provisions new ones. The right gate when staff use
    |    personal Gmail addresses, where the domain proves nothing.
    |  - allowed_domains: only for a real company domain. Useless against
    |    gmail.com, which everybody shares.
    */
    'oidc' => [
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'issuer' => env('OIDC_ISSUER'),
        'authorize_url' => env('OIDC_AUTHORIZE_URL'),
        'token_url' => env('OIDC_TOKEN_URL'),
        'userinfo_url' => env('OIDC_USERINFO_URL'),
        'redirect' => env('OIDC_REDIRECT_URL'),
        'scopes' => env('OIDC_SCOPES', 'openid email profile'),
        'allowed_domains' => env('OIDC_ALLOWED_DOMAINS'),
        'require_existing_user' => env('OIDC_REQUIRE_EXISTING_USER', true),
        'label' => env('OIDC_LABEL', 'SSO'),
    ],

    /*
    | Sentry's browser SDK key, read by partials/pwa-head.blade.php and used in
    | resources/js/sentry.js. Kept here rather than in config/sentry.php: that file is
    | handed to the PHP SDK verbatim as its option array, so an extra key there makes the
    | client log "Option ... does not exist and will be ignored" on every single init.
    |
    | Separate from SENTRY_LARAVEL_DSN because Sentry keeps one project per platform, and
    | server faults and browser faults read better apart. Set both to the same value when
    | only one project exists — neither SDK minds.
    |
    | Public by design: it ships in the page source to every visitor. It permits posting
    | events to one project and grants nothing else.
    */
    'sentry' => [
        'browser_dsn' => env('SENTRY_BROWSER_DSN'),
    ],

];
