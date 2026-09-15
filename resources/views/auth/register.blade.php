@php
    /** @var \App\Models\CompanyInvite $invite */
    /** @var string $state  form | superadmin | used | expired */
    $me = auth()->user();
    $field = 'width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;font-family:inherit;';
    $label = 'display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up your company · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;display:flex;background:var(--canvas);">
    <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:48px;">
        <div style="width:100%;max-width:380px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:36px;">
                <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
                <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            </div>

            @if ($state === 'superadmin')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">You already see every company</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Signup links are for the person who will run the new company. As a super admin you create companies from the Companies page, or send this link on to them.</p>
                <a href="{{ route('superadmin.companies.index') }}" class="uj-btn-primary" style="display:inline-flex;align-items:center;height:44px;padding:0 18px;font-size:14px;text-decoration:none;">Go to Companies</a>
            @elseif ($state === 'used')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">This link has already been used</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Each signup link creates one company. If that company is yours, sign in. If you need a new company, ask for a fresh link.</p>
                <a href="{{ route('login') }}" class="uj-btn-primary" style="display:inline-flex;align-items:center;height:44px;padding:0 18px;font-size:14px;text-decoration:none;">Sign in</a>
            @elseif ($state === 'expired')
                <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">This link has expired</h1>
                <p style="font-size:14px;color:var(--muted);margin:0 0 24px;line-height:1.6;">Signup links last 7 days. Ask the person who sent it for a new one.</p>
            @else
                <form action="{{ route('register.store') }}" method="post">
                    @csrf
                    {{-- The token rides in the form so a validation failure re-renders with it. --}}
                    <input type="hidden" name="invite" value="{{ $invite->token }}">

                    <span style="display:inline-block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#7a4f10;background:#fdf3e3;border:1px solid #f0d9a8;padding:3px 9px;border-radius:9999px;margin-bottom:14px;">Invited · link valid until {{ $invite->expires_at->format('j M') }}</span>
                    <h1 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">Set up your company</h1>
                    <p style="font-size:14px;color:var(--muted);margin:0 0 28px;">You will be the HR admin. You can add branches, staff and everything else after this.</p>

                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:8px;padding:10px 14px;margin-bottom:18px;line-height:1.5;">
                            {{ $errors->first() }}
                            @if ($errors->has('email'))
                                <a href="{{ route('login') }}" style="color:var(--red);font-weight:600;">Sign in</a>, then open this link again.
                            @endif
                        </div>
                    @endif

                    <label style="{{ $label }}">Company name</label>
                    <input name="company_name" type="text" value="{{ old('company_name') }}" autocomplete="organization" required autofocus style="{{ $field }}" />

                    @if ($me)
                        <p style="font-size:13px;color:var(--muted);margin:0 0 24px;line-height:1.55;">You are signed in as <strong style="color:var(--ink);">{{ $me->name }}</strong> ({{ $me->email }}). This account becomes the HR admin of the new company.</p>
                    @else
                        <label style="{{ $label }}">Your full name</label>
                        <input name="name" type="text" value="{{ old('name') }}" autocomplete="name" required style="{{ $field }}" />

                        <label style="{{ $label }}">Your email</label>
                        <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required style="{{ $field }}" />

                        <label style="{{ $label }}">Password</label>
                        <input name="password" type="password" autocomplete="new-password" required style="{{ $field }}" />

                        <label style="{{ $label }}">Confirm password</label>
                        <input name="password_confirmation" type="password" autocomplete="new-password" required style="{{ $field }}margin-bottom:24px;" />
                    @endif

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:46px;font-size:14px;">Create company</button>

                    @unless ($me)
                        <p style="font-size:13px;color:var(--muted);margin-top:22px;text-align:center;">Already have an account? <a href="{{ route('login') }}" style="color:var(--red);text-decoration:none;font-weight:500;">Sign in</a></p>
                    @endunless
                </form>
            @endif
        </div>
    </div>

    <div style="flex:1;background:var(--sidebar);color:#fff;padding:64px;display:flex;flex-direction:column;justify-content:center;">
        <div style="max-width:420px;">
            <div style="font-size:11px;font-weight:600;letter-spacing:0.88px;text-transform:uppercase;color:var(--red);margin-bottom:20px;">What happens next</div>
            <h2 style="font-weight:400;font-size:30px;line-height:1.25;letter-spacing:-0.6px;color:#fff;margin:0 0 24px;">Your company, your setup.</h2>
            <ol style="font-size:15px;line-height:1.7;color:#b8b6ad;margin:0;padding-left:20px;">
                <li>Create your company and admin login (this page).</li>
                <li>Land in <strong style="color:#fff;font-weight:500;">Launch Center</strong>: pick the modules you need, set your work week, add branches and staff.</li>
                <li>Launch. Your staff sign in and start using it.</li>
            </ol>
        </div>
    </div>
</div>
</body>
</html>
