/**
 * Browser error reporting.
 *
 * This is the half of the picture no server-side tool can reach. A clock-in where the
 * phone refuses or times out on geolocation never becomes a request at all, so it appears
 * in no log, no attendance_attempts row and no Laravel exception — the punch simply does
 * not happen and the only evidence is the employee saying it did not work. Reported from
 * here, that failure arrives with the device, the OS and the browser version attached.
 *
 * Configuration comes from meta tags (see partials/pwa-head.blade.php), not from the build,
 * because public/build is compiled locally and committed: a value baked into the bundle
 * could only be changed by rebuilding it.
 */
import * as Sentry from '@sentry/browser';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content?.trim() || '';

export function registerSentry() {
    const dsn = meta('sentry-dsn');

    // No DSN configured on this host: report nothing, and above all do not try to. An SDK
    // pointed at an empty DSN is not silent, it is a source of console noise on every page.
    if (dsn === '') {
        return;
    }

    Sentry.init({
        dsn,
        environment: meta('sentry-environment') || 'unknown',
        // Errors only. Tracing samples ordinary successful requests, which costs quota and
        // answers a question nobody here is asking yet.
        tracesSampleRate: 0,
        // The default set includes Breadcrumbs, which records the clicks and fetches leading
        // up to a fault — the part that shows how far a punch got before it stopped.
        //
        // sendDefaultPii stays off, matching config/sentry.php: with it on the SDK attaches
        // the request body and the URL's own query string, and this application's bodies
        // carry GPS coordinates and typed reasons.
        sendDefaultPii: false,
    });

    const userId = meta('sentry-user');
    if (userId !== '') {
        // Id alone. Enough to tell "one person" from "everybody", which is the only question
        // a fault report needs a user for.
        Sentry.setUser({ id: userId });
    }
}
