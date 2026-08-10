import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Self-hosted: the files are fetched from Bunny at BUILD time and vendored
            // into public/build, so nothing contacts a CDN at runtime. `Vite::fonts()`
            // in the Blade layouts is what links them; without that call the browser
            // never sees an @font-face and silently uses the system UI font.
            //
            // `preload` is narrowed from its default (every weight, ~30 <link>s) to the
            // four that carry the shell's first paint. Measured on the dashboard above
            // the fold: sans 500 (29 elements), mono 600 (19), sans 400 (17), sans 600
            // (12) — together 77 of 91. The rest (sans 700, mono 400/500) still load,
            // just on demand via the inlined @font-face, trading a brief swap on those
            // few labels for roughly half the requests on every page load.
            fonts: [
                bunny('Poppins', {
                    weights: [400, 500, 600, 700],
                    preload: [{ weight: 400 }, { weight: 500 }, { weight: 600 }],
                }),
                // 600 is not optional: the type ramp sets mono counts, badges and the
                // sidebar clock at 600, and without the real face the browser fakes
                // it by smearing the 500 outlines.
                bunny('JetBrains Mono', {
                    weights: [400, 500, 600],
                    preload: [{ weight: 600 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
    // Sentry's documented tree-shaking flags. Its browser SDK bundles performance tracing
    // and debug logging by default and this app uses neither: resources/js/sentry.js sets
    // tracesSampleRate to 0, so that code would ship to every phone and never run.
    //
    // Measured, because the honest number is small: 74.8KB gzipped with these flags against
    // 76.4KB without. The SDK itself is the weight, not its tracing — app.js went from 46KB
    // to 72KB gzipped when Sentry arrived, and no build flag gives that back. Kept anyway:
    // two lines, and dead code is worth dropping even by the kilobyte on a product whose
    // reported problem is people on phones.
    define: {
        __SENTRY_TRACING__: false,
        __SENTRY_DEBUG__: false,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
