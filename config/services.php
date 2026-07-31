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
    ],

];
