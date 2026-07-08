<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor authentication · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php $fs = 'width:100%;height:46px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:16px;letter-spacing:3px;text-align:center;font-family:var(--font-mono);color:var(--ink);background:#fff;margin-bottom:20px;outline:none;'; @endphp
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--canvas);padding:48px;" x-data="{ recovery: false }">
    <form action="{{ route('two-factor.login.store') }}" method="post" style="width:100%;max-width:380px;">
        @csrf
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:36px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
        </div>
        <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 8px;">Two-factor authentication</h1>

        @if ($errors->any())
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:8px;padding:10px 14px;margin-bottom:18px;">{{ $errors->first() }}</div>
        @endif

        <div x-show="! recovery">
            <p style="font-size:14px;color:var(--muted);margin:0 0 24px;">Enter the 6-digit code from your authenticator app.</p>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="000000" style="{{ $fs }}" />
        </div>
        <div x-show="recovery" x-cloak>
            <p style="font-size:14px;color:var(--muted);margin:0 0 24px;">Enter one of your saved recovery codes.</p>
            <input name="recovery_code" autocomplete="one-time-code" placeholder="xxxxxxxx-xxxxxxxx" style="{{ $fs }}letter-spacing:1px;" />
        </div>

        <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;">Verify</button>
        <p style="margin-top:20px;text-align:center;">
            <button type="button" @click="recovery = ! recovery" style="font-size:13px;color:var(--red);background:none;cursor:pointer;"><span x-text="recovery ? 'Use an authentication code' : 'Use a recovery code instead'"></span></button>
        </p>
    </form>
</div>
</body>
</html>
