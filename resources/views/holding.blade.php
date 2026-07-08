<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Setting up · Amanahku</title>
    @vite(['resources/css/app.css'])
</head>
<body style="background:var(--canvas);">
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div class="uj-card" style="max-width:440px;width:100%;padding:34px 30px;text-align:center;">
        <div style="width:54px;height:54px;border-radius:9999px;background:var(--canvas);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
        </div>
        <h1 style="font-size:19px;font-weight:600;color:var(--ink);margin:0 0 8px;">Your workspace is being set up</h1>
        <p style="font-size:13.5px;color:var(--muted);margin:0 0 4px;">Your HR team is still configuring Amanahku. You'll be able to sign in and start once setup is complete.</p>
        <p style="font-size:12.5px;color:var(--muted-soft);margin:0 0 22px;">Ruang kerja anda sedang disediakan oleh pasukan HR. Sila cuba semula sebentar lagi.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="padding:9px 20px;">Sign out</button>
        </form>
    </div>
</div>
</body>
</html>
