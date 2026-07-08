<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers applied to every response. A strict Content-Security-Policy
 * is intentionally NOT set here yet — the UI uses inline styles + Alpine expressions, so
 * CSP needs a nonce refactor first (tracked in docs/ISSUES / README hardening checklist).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Transitional CSP. The UI still relies on inline styles + a few inline scripts
        // (passkey ceremony, Alpine expressions), so script/style-src must allow
        // 'unsafe-inline'. Alpine.js (default build) compiles every x-data/x-show/@click
        // through new Function(), which the browser treats as eval — that needs
        // 'unsafe-eval', NOT 'unsafe-inline'. Without it ALL Alpine interactivity silently
        // dies. Kept until a nonce + Alpine CSP-build refactor lands (tracked in README
        // hardening checklist). Even so, these directives are a real gain today:
        // frame-ancestors blocks clickjacking, base-uri/object-src/form-action close
        // base-tag, plugin and form-hijack vectors. self-host only — no external CDNs.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            // OpenStreetMap raster tiles power the Attendance Setup map picker.
            // Tiles load as <img>, so img-src needs the tile host.
            "img-src 'self' data: https://*.tile.openstreetmap.org",
            "font-src 'self'",
            // The map picker's address search calls Nominatim over fetch/XHR,
            // so connect-src must allow the geocoder host.
            "connect-src 'self' https://nominatim.openstreetmap.org",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]));

        // HSTS only over HTTPS — sending it on plain HTTP (local dev) is ignored by
        // browsers and pinning a non-TLS host for a year would be a footgun. Once the
        // app is served over TLS in production this forces every future request to HTTPS.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
