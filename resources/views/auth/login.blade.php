@php
    /** @var \App\Models\Tenant|null $brandTenant — set on the company-branded /login/{slug} route. */
    $brandTenant = $brandTenant ?? null;
    // Root-relative so the logo loads on any host/port without depending on APP_URL.
    $brandLogo = $brandTenant?->logo_path ? '/storage/'.$brandTenant->logo_path : null;
    $brandColor = $brandTenant?->color ?: config('amanahku.brand_color');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ $brandTenant?->name ?? 'Amanahku' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Login-scoped polish. Inline styles can't express :focus / :hover / media queries. */
        .lg-field { transition: border-color .15s, box-shadow .15s; }
        .lg-field:focus { border-color: var(--red); box-shadow: 0 0 0 3px var(--red-tint); }
        .lg-primary { transition: background .15s, transform .04s; }
        .lg-primary:active { transform: translateY(1px); }
        .lg-passkey { transition: border-color .15s, background .15s; }
        .lg-passkey:hover { border-color: var(--muted-soft); background: var(--canvas); }
        .lg-quick { transition: border-color .15s, background .15s, transform .04s; }
        .lg-quick:hover { border-color: var(--red); background: var(--red-tint); }
        .lg-quick:active { transform: translateY(1px); }
        .lg-link { transition: opacity .15s; }
        .lg-link:hover { opacity: .7; }
        /* Animated hero grid + slow drift. */
        @keyframes lg-drift { from { background-position: 0 0, 0 0; } to { background-position: 56px 56px, 56px 56px; } }
        @media (max-width: 880px) {
            .lg-hero { display: none !important; }
            .lg-formwrap { padding: 32px 22px !important; }
        }
    </style>
</head>
<body>
<div style="min-height:100vh;display:flex;background:var(--canvas);">

    {{-- ── Brand hero (left) ── --}}
    <div class="lg-hero" style="flex:1.05;position:relative;overflow:hidden;color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:space-between;background:radial-gradient(120% 120% at 0% 0%, #2b2a25 0%, var(--sidebar) 55%, #161510 100%);">
        {{-- Grid texture --}}
        <div aria-hidden="true" style="position:absolute;inset:0;opacity:.5;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px);background-size:56px 56px;animation:lg-drift 40s linear infinite;"></div>
        {{-- Warm accent glow --}}
        <div aria-hidden="true" style="position:absolute;top:-120px;right:-120px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle, rgba(214,35,43,.22), transparent 70%);pointer-events:none;"></div>

        {{-- Wordmark --}}
        <div style="position:relative;display:flex;align-items:center;gap:11px;">
            @if ($brandTenant && $brandLogo)
                <img src="{{ $brandLogo }}" alt="{{ $brandTenant->name }}" style="width:34px;height:34px;border-radius:9px;object-fit:cover;">
            @else
                <div style="width:34px;height:34px;border-radius:9px;background:{{ $brandColor }};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">{{ $brandTenant?->initials ?? 'A' }}</div>
            @endif
            <div>
                @if ($brandTenant)
                    <div style="font-weight:600;font-size:18px;letter-spacing:-0.2px;">{{ $brandTenant->name }}</div>
                    <div style="font-size:10.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:#807d72;">Powered by Amanahku</div>
                @else
                    <div style="font-weight:600;font-size:18px;letter-spacing:-0.2px;">Amanah<span style="color:#ff6b6b;">ku</span></div>
                    <div style="font-size:10.5px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:#807d72;">Workforce OS</div>
                @endif
            </div>
        </div>

        {{-- Headline --}}
        <div style="position:relative;max-width:460px;">
            @if ($brandTenant)
                <div style="font-size:11px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:#ff6b6b;margin-bottom:22px;">{{ $brandTenant->name }} workspace</div>
                <h1 style="font-weight:400;font-size:38px;line-height:1.2;letter-spacing:-1px;color:#fff;margin:0 0 22px;">{{ $brandTenant->welcome_message ?: 'Welcome to your '.$brandTenant->name.' workspace.' }}</h1>
                <p style="font-size:15px;line-height:1.65;color:#b8b6ad;margin:0;max-width:400px;">Sign in to your {{ $brandTenant->name }} workforce management workspace on Amanahku.</p>
            @else
                <div style="font-size:11px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;color:#ff6b6b;margin-bottom:22px;">HR · Work · AI Intelligence</div>
                <h1 style="font-weight:400;font-size:40px;line-height:1.18;letter-spacing:-1px;color:#fff;margin:0 0 22px;">Know who is working,<br>on what, and what<br>to do <span style="color:#ff6b6b;font-style:italic;font-family:var(--font-serif,Georgia,serif);">next.</span></h1>
                <p style="font-size:15px;line-height:1.65;color:#b8b6ad;margin:0 0 30px;max-width:400px;">One platform for workforce management, weekly work tracking, and AI capacity intelligence — built for medium-sized companies.</p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    @foreach (['Workforce', 'Weekly tracking', 'Payroll', 'AI assistant'] as $chip)
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#d8d6cd;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:9999px;padding:6px 13px;">
                            <span style="width:5px;height:5px;border-radius:50%;background:#ff6b6b;"></span>{{ $chip }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Stat row + footer --}}
        <div style="position:relative;">
            @unless ($brandTenant)
                <div style="display:flex;gap:40px;margin-bottom:28px;">
                    <div><div style="font-size:28px;font-weight:600;color:#fff;font-family:var(--font-mono);letter-spacing:-1px;">3</div><div style="font-size:12.5px;color:#807d72;">tenants live</div></div>
                    <div><div style="font-size:28px;font-weight:600;color:#fff;font-family:var(--font-mono);letter-spacing:-1px;">412</div><div style="font-size:12.5px;color:#807d72;">employees</div></div>
                    <div><div style="font-size:28px;font-weight:600;color:#fff;font-family:var(--font-mono);letter-spacing:-1px;">17</div><div style="font-size:12.5px;color:#807d72;">modules</div></div>
                </div>
            @endunless
            <div style="font-size:11.5px;color:#615f57;">© {{ date('Y') }} Amanahku{{ $brandTenant ? ' · '.$brandTenant->name : '' }}</div>
        </div>
    </div>

    {{-- ── Sign-in form (right) ── --}}
    <div class="lg-formwrap" style="flex:1;display:flex;align-items:center;justify-content:center;padding:48px;">
        <form action="/login" method="post" style="width:100%;max-width:380px;">
            @csrf
            @if ($brandTenant)<input type="hidden" name="tenant_slug" value="{{ $brandTenant->slug }}">@endif

            {{-- Compact brand (shows when hero is hidden) --}}
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:34px;">
                @if ($brandTenant && $brandLogo)
                    <img src="{{ $brandLogo }}" alt="{{ $brandTenant->name }}" style="width:30px;height:30px;border-radius:7px;object-fit:cover;">
                    <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">{{ $brandTenant->name }}</span>
                @elseif ($brandTenant)
                    <div style="width:30px;height:30px;border-radius:7px;background:{{ $brandColor }};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;">{{ $brandTenant->initials }}</div>
                    <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">{{ $brandTenant->name }}</span>
                @else
                    <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
                    <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
                @endif
            </div>

            <h2 style="font-weight:400;font-size:30px;letter-spacing:-0.6px;color:var(--ink);margin:0 0 8px;">Welcome back</h2>
            <p style="font-size:14px;color:var(--muted);margin:0 0 26px;">Sign in to your workforce management workspace.</p>

            @if ($errors->any())
                <div style="display:flex;align-items:flex-start;gap:9px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:9px;padding:11px 14px;margin-bottom:20px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4M12 16h.01"></path></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            @if (! $brandTenant && app()->isLocal())
            {{-- Quick login (demo) — local development only; never rendered in staging/production. --}}
            <div style="margin-bottom:22px;">
                <div style="font-size:11px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--muted-soft);margin-bottom:9px;">Quick login · demo</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <button type="button" class="lg-quick" onclick="quickLogin('aisyah.rahman@unijaya.example')"
                            style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;background:#fff;text-align:left;cursor:pointer;">
                        <span style="font-size:13px;font-weight:600;color:var(--ink);">HR · Manager</span>
                        <span style="font-size:11px;color:var(--muted);">Aisyah Rahman</span>
                    </button>
                    <button type="button" class="lg-quick" onclick="quickLogin('superadmin@amanahku.com')"
                            style="display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;background:#fff;text-align:left;cursor:pointer;">
                        <span style="font-size:13px;font-weight:600;color:var(--ink);">Super Admin</span>
                        <span style="font-size:11px;color:var(--muted);">Platform console</span>
                    </button>
                </div>
                <p style="font-size:11.5px;color:var(--muted-soft);margin:8px 0 0;">One click signs you in. HR demo picks a workspace next: Unijaya (HR), Shell (Manager), Petron (Employee).</p>
            </div>

            <div style="display:flex;align-items:center;gap:10px;margin:0 0 22px;"><span style="flex:1;height:1px;background:var(--hairline);"></span><span style="font-size:11px;color:var(--muted-soft);text-transform:uppercase;letter-spacing:0.5px;">or sign in</span><span style="flex:1;height:1px;background:var(--hairline);"></span></div>
            @endif

            <label for="email" style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required class="lg-field" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:18px;outline:none;" />

            <label for="password" style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="lg-field" style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;color:var(--ink);background:#fff;margin-bottom:14px;outline:none;" />

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--body);cursor:pointer;"><input type="checkbox" name="remember" checked style="accent-color:var(--red);width:15px;height:15px;" /> Remember me</label>
                <a href="{{ route('password.request') }}" class="lg-link" style="font-size:13px;color:var(--red);text-decoration:none;">Forgot password?</a>
            </div>

            <button type="submit" class="uj-btn-primary lg-primary" style="width:100%;height:46px;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;">
                Sign in
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
            </button>

            <div style="display:flex;align-items:center;gap:10px;margin:18px 0;"><span style="flex:1;height:1px;background:var(--hairline);"></span><span style="font-size:11px;color:var(--muted-soft);text-transform:uppercase;letter-spacing:0.5px;">or</span><span style="flex:1;height:1px;background:var(--hairline);"></span></div>

            <button type="button" id="passkey-signin" class="lg-passkey" style="width:100%;height:46px;font-size:14px;background:#fff;border:1px solid var(--hairline);border-radius:9px;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v3h6v-3M12 2a5 5 0 0 0-5 5c0 2 1 3 1 3M12 2a5 5 0 0 1 5 5M12 7v.01M8 21l4-9 4 9"/></svg>
                Sign in with a passkey
            </button>
            <div id="passkey-error" style="display:none;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:9px;padding:9px 12px;margin-top:12px;"></div>

            @if (\App\Services\OidcClient::fromConfig()->configured())
                <a href="{{ route('oidc.redirect') }}" class="lg-passkey" style="width:100%;height:46px;margin-top:12px;font-size:14px;background:#fff;border:1px solid var(--hairline);border-radius:9px;color:var(--ink);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Sign in with SSO
                </a>
            @endif

            @unless ($brandTenant)
                <p style="font-size:13px;color:var(--muted);margin-top:22px;text-align:center;">New to Amanahku? <a href="{{ route('register') }}" class="lg-link" style="color:var(--red);text-decoration:none;font-weight:500;">Create an account</a></p>
                @if (app()->isLocal())
                    {{-- Demo credential hint — local development only. --}}
                    <div style="margin-top:14px;background:var(--canvas);border:1px dashed var(--hairline);border-radius:9px;padding:10px 12px;font-size:12px;color:var(--muted);text-align:center;">Demo password — <span style="color:var(--ink);font-weight:600;font-family:var(--font-mono);">password</span></div>
                @endif
            @else
                @if ($brandTenant->email || $brandTenant->contact_number)
                    <p style="font-size:12.5px;color:var(--muted);margin-top:22px;text-align:center;">Need help signing in? Contact <span style="color:var(--ink);">{{ $brandTenant->email ?: $brandTenant->contact_number }}</span></p>
                @endif
            @endunless
            <p style="font-size:11.5px;color:var(--muted-soft);margin-top:18px;text-align:center;">Protected by Amanahku · Multi-tenant SSO</p>
        </form>
    </div>
</div>

<script>
    @if (app()->isLocal())
    // Quick-login (demo): fill credentials and submit. Local development only — the
    // buttons that call this are not rendered outside local, and neither is this code.
    function quickLogin(email) {
        document.getElementById('email').value = email;
        document.getElementById('password').value = 'password';
        document.querySelector('form[action="/login"]').submit();
    }
    @endif

    document.getElementById('passkey-signin')?.addEventListener('click', async function () {
        const err = document.getElementById('passkey-error');
        err.style.display = 'none';
        if (!window.Passkey || !window.Passkey.supported()) {
            err.textContent = 'This browser does not support passkeys.';
            err.style.display = 'block';
            return;
        }
        this.disabled = true;
        const original = this.textContent;
        this.textContent = 'Waiting for passkey…';
        try {
            await window.Passkey.login('{{ csrf_token() }}');
            window.location = '/';
        } catch (e) {
            err.textContent = e.message || 'Passkey sign-in failed.';
            err.style.display = 'block';
            this.disabled = false;
            this.textContent = original;
        }
    });
</script>
</body>
</html>
