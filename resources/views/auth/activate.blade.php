<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your account · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--canvas);padding:24px;">
    <div style="max-width:400px;width:100%;background:#fff;border:1px solid var(--hairline,#e6e6ec);border-radius:16px;padding:34px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);">Amanah<span style="color:var(--red);">ku</span></span>
        </div>

        <h1 style="font-weight:500;font-size:23px;color:var(--ink);margin:0 0 6px;letter-spacing:-0.3px;">Activate your account</h1>
        <p style="font-size:14px;color:var(--muted);margin:0 0 22px;">Hi {{ $user->name }} — set a password to finish setting up your Amanahku account.</p>

        @if ($errors->any())
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:9px;padding:11px 14px;margin-bottom:18px;">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ request()->fullUrl() }}">
            @csrf
            <label for="password" style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;margin-bottom:16px;outline:none;" />

            <label for="password_confirmation" style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;margin-bottom:22px;outline:none;" />

            <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;">Activate account</button>
        </form>

        <p style="font-size:12px;color:var(--muted-soft);margin-top:18px;text-align:center;">Prefer to use the temporary password from your email? <a href="/login" style="color:var(--red);text-decoration:none;">Sign in instead</a>.</p>
    </div>
</div>
</body>
</html>
