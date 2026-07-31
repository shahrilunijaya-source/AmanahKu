<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Read-only access · Amanahku</title>
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
        <div style="width:54px;height:54px;border-radius:14px;background:var(--amber-tint,#fdf3e3);color:var(--amber,#b26b00);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        </div>
        <h1 style="font-weight:500;font-size:22px;color:var(--ink);margin:0 0 10px;letter-spacing:-0.3px;">
            Observer access is read-only
        </h1>
        <p style="font-size:14px;color:var(--muted);line-height:1.6;margin:0 0 24px;">
            You are inside <strong style="color:var(--ink);">{{ $tenant->name }}</strong> as a super-admin observer,
            not as a member of this company. You can see every screen, but you cannot change anything here.
            To act inside this company, have it grant you a real membership.
        </p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <a href="{{ url()->previous() }}" style="text-decoration:none;padding:11px 18px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:13.5px;font-weight:600;color:var(--ink);background:#fff;">Go back</a>
            <a href="{{ route('superadmin.companies.index') }}" style="text-decoration:none;padding:11px 18px;border-radius:10px;font-size:13.5px;font-weight:600;color:#fff;background:var(--ink);">Admin console</a>
        </div>
    </div>
</div>
</body>
</html>
