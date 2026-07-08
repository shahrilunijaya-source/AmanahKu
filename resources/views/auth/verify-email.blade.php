<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);display:flex;align-items:center;justify-content:center;padding:48px 24px;">
    <div style="width:100%;max-width:420px;text-align:center;">
        <div style="display:inline-flex;align-items:center;gap:10px;margin-bottom:32px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);">Amanah<span style="color:var(--red);">ku</span></span>
        </div>

        <h1 style="font-weight:400;font-size:26px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 10px;">Verify your email</h1>
        <p style="font-size:14px;color:var(--muted);line-height:1.6;margin:0 0 24px;">We sent a verification link to your inbox. Click it to activate your account. Didn't get it? Resend below.</p>

        @if (session('status') === 'verification-link-sent')
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13.5px;">A fresh verification link has been sent to your email.</div>
        @endif

        <div style="display:flex;gap:12px;justify-content:center;">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 22px;font-size:14px;">Resend link</button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" style="height:44px;padding:0 22px;font-size:14px;background:#fff;border:1px solid var(--hairline);border-radius:8px;color:var(--ink);cursor:pointer;">Sign out</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
