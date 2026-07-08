<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set your password · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php $fs = 'width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;'; @endphp
<div style="min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--canvas);padding:48px;">
    <form action="{{ route('password.change.update') }}" method="post" style="width:100%;max-width:380px;">
        @csrf
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:36px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
        </div>
        <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 8px;">Set your password</h1>
        <p style="font-size:14px;color:var(--muted);margin:0 0 28px;">You signed in with a one-time password. Choose a new password to continue.</p>

        @if ($errors->any())
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:8px;padding:10px 14px;margin-bottom:18px;">{{ $errors->first() }}</div>
        @endif

        <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Temporary password</label>
        <input name="current_password" type="password" autocomplete="current-password" required autofocus style="{{ $fs }}" />

        <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">New password</label>
        <input name="password" type="password" autocomplete="new-password" required style="{{ $fs }}" />

        <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Confirm new password</label>
        <input name="password_confirmation" type="password" autocomplete="new-password" required style="{{ $fs }}" />

        <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;margin-top:2px;">Save and continue</button>
    </form>
    <form action="{{ route('logout') }}" method="post" style="margin-top:16px;text-align:center;width:100%;max-width:380px;">
        @csrf
        <button type="submit" style="background:none;border:none;font-size:13px;color:var(--red);cursor:pointer;">← Sign out</button>
    </form>
</div>
</body>
</html>
