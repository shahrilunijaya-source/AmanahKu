{{-- Installable web app. Must be on EVERY page with its own <head>: a user can add to
     the Home Screen from any screen, and the browser only reads these tags from the page
     it is on. On iOS this is also the only route to notifications at all: Safari shows
     them just for a web app added to the Home Screen. --}}
<link rel="icon" href="/icons/icon-192.png" type="image/png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#d6232b">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Amanahku">

{{-- Browser error reporting. Read by resources/js/sentry.js; empty content disables it,
     so a host with no DSN configured ships no reporting and no failed requests.

     Carried here rather than baked into the bundle by Vite because public/build is built
     locally and committed: a DSN compiled into an asset could only be changed by rebuilding
     and redeploying the whole bundle, while a config value read at render time changes with
     an .env edit, which is all a host operator has.

     User id only, never name or email. Enough to see whether one person or everybody is
     affected, and nothing a third party has any use for. --}}
<meta name="sentry-dsn" content="{{ config('services.sentry.browser_dsn') }}">
<meta name="sentry-environment" content="{{ config('app.env') }}">
<meta name="sentry-user" content="{{ auth()->id() ?? '' }}">
