<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select workspace · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);display:flex;flex-direction:column;align-items:center;padding:64px 24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:40px;">
        <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
        <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
    </div>
    <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 6px;text-align:center;">Select a workspace</h1>
    <p style="font-size:14px;color:var(--muted);margin:0 0 36px;text-align:center;">You have access to multiple companies. Choose one to continue.</p>

    @unless (auth()->user()->hasVerifiedEmail())
        <div style="width:100%;max-width:660px;margin-bottom:16px;background:#fbf6e9;border:1px solid #ecd9a6;border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:14px;">
            <div style="font-size:13.5px;color:#7a5d00;line-height:1.5;flex:1;">Please verify your email to secure your account. Check your inbox for the link.</div>
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" style="white-space:nowrap;height:38px;padding:0 16px;font-size:13px;font-weight:600;background:#fff;border:1px solid #ecd9a6;border-radius:8px;color:#7a5d00;cursor:pointer;">Resend link</button>
            </form>
        </div>
    @endunless

    @if ($tenants->isEmpty() && ! auth()->user()->isSuperAdmin())
        <div style="width:100%;max-width:660px;background:var(--surface,#fff);border:1px solid var(--hairline);border-radius:14px;padding:36px;text-align:center;">
            <div style="width:54px;height:54px;border-radius:14px;background:var(--hairline-soft);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;">🏢</div>
            <h2 style="font-weight:600;font-size:18px;color:var(--ink);margin:0 0 8px;">No workspace yet</h2>
            <p style="font-size:14px;color:var(--muted);line-height:1.6;margin:0;">Your account is ready, but it isn't linked to a company. Ask your HR admin to add you with this email, or contact the Amanahku team to get set up.</p>
        </div>
    @endif

    @if (auth()->user()->isSuperAdmin())
        <a href="{{ route('superadmin.companies.index') }}" class="uj-btn-ghost" style="text-align:left;padding:18px 20px;display:flex;align-items:center;gap:14px;text-decoration:none;border-radius:12px;width:100%;max-width:660px;margin-bottom:16px;border:1px dashed var(--red);">
            <div style="width:46px;height:46px;border-radius:10px;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex-shrink:0;">★</div>
            <div style="flex:1;">
                <div style="font-weight:600;font-size:15px;color:var(--ink);">Super Admin Console</div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:2px;">Provision and manage companies across the platform</div>
            </div>
            <div style="font-size:18px;color:var(--muted);">→</div>
        </a>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;width:100%;max-width:660px;">
        @foreach ($tenants as $t)
            <a href="{{ route('tenant.enter', $t) }}" class="uj-btn-ghost" style="text-align:left;padding:20px;display:flex;align-items:center;gap:16px;text-decoration:none;border-radius:12px;">
                <div style="width:46px;height:46px;border-radius:10px;background:{{ $t->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex-shrink:0;">{{ $t->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:15px;color:var(--ink);">{{ $t->name }}</div>
                    <div style="font-size:12.5px;color:var(--muted);margin-top:2px;">{{ $t->metaLine }} · {{ ucfirst($t->pivot->role) }}</div>
                </div>
                <div style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;">{{ $t->plan }}</div>
            </a>
        @endforeach
    </div>
    <div style="margin-top:28px;display:flex;align-items:center;gap:20px;">
        <a href="#" style="font-size:13px;color:var(--muted);text-decoration:none;">+ Request access to another company</a>
        <form action="/logout" method="post" style="display:inline;">
            @csrf
            <button type="submit" style="font-size:13px;font-weight:600;color:var(--red);background:none;border:0;cursor:pointer;padding:0;">Sign out</button>
        </form>
    </div>
</div>
</body>
</html>
