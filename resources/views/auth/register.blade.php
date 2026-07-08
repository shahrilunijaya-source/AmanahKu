<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;display:flex;background:var(--canvas);">
    <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:48px;">
        <form action="{{ route('register') }}" method="post" style="width:100%;max-width:380px;">
            @csrf
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:40px;">
                <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
                <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            </div>
            <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">Create your account</h1>
            <p style="font-size:14px;color:var(--muted);margin:0 0 32px;">Sign up to get started. Your HR admin or our team will connect you to a company.</p>

            @if ($errors->any())
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:8px;padding:10px 14px;margin-bottom:18px;">{{ $errors->first() }}</div>
            @endif

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Full name</label>
            <input name="name" type="text" value="{{ old('name') }}" autocomplete="name" required style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Work email</label>
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Password</label>
            <input name="password" type="password" autocomplete="new-password" required style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Confirm password</label>
            <input name="password_confirmation" type="password" autocomplete="new-password" required style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:24px;outline:none;" />

            <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;">Create account</button>

            <p style="font-size:13px;color:var(--muted);margin-top:22px;text-align:center;">Already have an account? <a href="{{ route('login') }}" style="color:var(--red);text-decoration:none;font-weight:500;">Sign in</a></p>
        </form>
    </div>

    <div style="flex:1;background:var(--sidebar);color:#fff;padding:64px;display:flex;flex-direction:column;justify-content:center;">
        <div style="max-width:420px;">
            <div style="font-size:11px;font-weight:600;letter-spacing:0.88px;text-transform:uppercase;color:var(--red);margin-bottom:20px;">HR · Work · AI Intelligence</div>
            <h2 style="font-weight:400;font-size:34px;line-height:1.25;letter-spacing:-0.7px;color:#fff;margin:0 0 20px;">One workspace for your whole workforce.</h2>
            <p style="font-size:15px;line-height:1.6;color:#b8b6ad;margin:0;">Leave, payroll, performance and AI-driven capacity intelligence — built for medium-sized companies in Malaysia.</p>
        </div>
    </div>
</div>
</body>
</html>
