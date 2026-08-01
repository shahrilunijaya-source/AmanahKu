<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--canvas);padding:24px;">
    <div style="max-width:440px;width:100%;background:#fff;border:1px solid var(--hairline,#e6e6ec);border-radius:16px;padding:36px;text-align:center;">
        <div style="width:54px;height:54px;border-radius:14px;background:var(--red-tint,#fcebec);color:var(--red,#d6232b);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        </div>
        <h1 style="font-weight:500;font-size:22px;color:var(--ink);margin:0 0 10px;letter-spacing:-0.3px;">Something went wrong</h1>
        <p style="font-size:14px;color:var(--muted);line-height:1.6;margin:0 0 22px;">
            The page could not be completed. Nothing you typed was saved. Please try again.
        </p>

        {{-- The reference is the whole point of this page. Support has no server access,
             so this code is the only handle they have on the fault the user just hit. --}}
        @if ($reference = \App\Models\ErrorEvent::currentReference())
            <div style="background:var(--hairline-soft,#f5f5f8);border:1px solid var(--hairline,#e6e6ec);border-radius:10px;padding:14px 18px;margin-bottom:22px;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:600;margin-bottom:6px;">Error reference</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:600;color:var(--ink);letter-spacing:1px;">{{ $reference }}</div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;margin-top:8px;">Quote this code when you report the problem.</div>
            </div>
        @endif

        <a href="/" style="display:inline-block;text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13.5px;font-weight:600;color:#fff;background:var(--red);">Back to Amanahku</a>
    </div>
</div>
</body>
</html>
