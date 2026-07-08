<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access removed · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--canvas);padding:24px;">
    <div style="max-width:440px;width:100%;background:#fff;border:1px solid var(--hairline,#e6e6ec);border-radius:16px;padding:36px;text-align:center;">
        <div style="width:54px;height:54px;border-radius:14px;background:var(--red-tint,#fcebec);color:var(--red,#d6232b);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M4.93 4.93l14.14 14.14"></path></svg>
        </div>
        <h1 style="font-weight:500;font-size:22px;color:var(--ink);margin:0 0 10px;letter-spacing:-0.3px;">
            Workspace access removed
        </h1>
        <p style="font-size:14px;color:var(--muted);line-height:1.6;margin:0 0 24px;">
            Your access to <strong style="color:var(--ink);">{{ $tenant->name }}</strong> has been removed —
            your staff record here is archived. If this is a mistake, contact your HR administrator to restore it.
        </p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <a href="{{ route('tenant.select') }}" style="text-decoration:none;padding:11px 18px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:13.5px;font-weight:600;color:var(--ink);background:#fff;">Switch workspace</a>
            <form action="/logout" method="post">@csrf<button type="submit" style="padding:11px 18px;border:none;border-radius:10px;font-size:13.5px;font-weight:600;color:#fff;background:var(--red);cursor:pointer;">Sign out</button></form>
        </div>
    </div>
</div>
</body>
</html>
