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
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* ── Login-scoped tokens ── */
        :root{
          --out:cubic-bezier(.23,1,.32,1);
          --ease-in-out:cubic-bezier(.77,0,.175,1);
        }
        *{box-sizing:border-box;}
        html,body{margin:0;height:100%;}
        body{
          font-family:var(--font-sans);
          color:var(--body);
          background:var(--canvas);
          -webkit-font-smoothing:antialiased;
          display:flex; flex-direction:column;
          align-items:center; justify-content:center;
          min-height:100vh; padding:32px 20px;
        }

        /* ── Card rise entrance ── */
        @keyframes lg-rise{
          from{ opacity:0; transform:translateY(14px) scale(.986); filter:blur(5px); }
          to  { opacity:1; transform:none; filter:blur(0); }
        }
        /* ── Corner stacks: solid red blocks anchored to opposite corners, three tones.
           Flat, crisp, static — the card rise is the only motion. Behind the stage. ── */
        .lg-bg{ position:fixed; inset:0; overflow:hidden; pointer-events:none; z-index:0; }
        .lg-bg .b{ position:absolute; }
        .lg-bg .b1{ left:0; bottom:0; width:clamp(120px,20vw,300px); height:clamp(150px,34vh,340px); background:var(--red); border-radius:0 14px 0 0; }
        .lg-bg .b2{ left:clamp(120px,20vw,300px); bottom:0; width:clamp(48px,7vw,110px); height:clamp(72px,18vh,180px); background:var(--red-active); }
        .lg-bg .b3{ right:0; top:0; width:clamp(130px,22vw,320px); height:clamp(130px,30vh,300px); background:var(--red-tint); border-radius:0 0 0 14px; }
        .lg-bg .b4{ right:0; top:0; width:clamp(46px,7vw,108px); height:clamp(60px,15vh,150px); background:var(--red); }

        .lg-stage{ position:relative; z-index:1; width:100%; max-width:404px; display:flex; flex-direction:column; align-items:center; }

        @media (max-width:640px){
          /* Drop the accents so the corners stay simple behind a near-full-width card. */
          .lg-bg .b2, .lg-bg .b4{ display:none; }
        }
        .lg-card{
          width:100%;
          background:#fff;
          border:1px solid var(--hairline);
          border-radius:16px;
          padding:38px 36px 32px;
          /* Defined edge (hairline) carries the card; one tight shadow lifts it off the canvas. */
          box-shadow:0 4px 14px -6px rgba(38,37,30,.14);
          animation:lg-rise .62s var(--out) both;
        }

        /* Brand lockup */
        .lg-brand{ display:flex; align-items:center; gap:11px; margin-bottom:26px; }
        .lg-logo{
          width:34px; height:34px; border-radius:9px;
          display:flex; align-items:center; justify-content:center;
          color:#fff; font-weight:700; font-size:17px; letter-spacing:-.02em;
          box-shadow:0 1px 2px rgba(214,35,43,.35);
        }
        .lg-word{ font-weight:600; font-size:18px; color:var(--ink); letter-spacing:-.02em; }
        .lg-word span{ color:var(--red); }

        /* Heading */
        .lg-h1{ font-size:25px; font-weight:600; color:var(--ink); letter-spacing:-.025em; margin:0 0 6px; line-height:1.15; }
        .lg-sub{ font-size:14px; color:var(--muted); margin:0 0 24px; line-height:1.5; }

        /* Quick login (LOCAL ONLY in production) */
        .lg-quick-label{ font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); margin-bottom:9px; }
        .lg-quick-grid{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .lg-quick{
          display:flex; flex-direction:column; align-items:flex-start; gap:2px;
          padding:10px 12px; border:1px solid var(--hairline); border-radius:11px;
          background:#fff; text-align:left; cursor:pointer; font-family:inherit;
          transition:border-color .15s var(--out), background .15s var(--out), transform .12s var(--out);
        }
        .lg-quick:hover{ border-color:var(--red); background:var(--red-tint); }
        .lg-quick:active{ transform:scale(.98); }
        .lg-quick b{ font-size:13px; font-weight:600; color:var(--ink); }
        .lg-quick span{ font-size:11px; color:var(--muted); }
        .lg-quick-hint{ font-size:11.5px; color:var(--muted); margin:9px 0 0; line-height:1.5; }

        /* Divider */
        .lg-div{ display:flex; align-items:center; gap:10px; margin:22px 0; }
        .lg-div span:first-child, .lg-div span:last-child{ flex:1; height:1px; background:var(--hairline); }
        .lg-div em{ font-size:11px; font-style:normal; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }

        /* Fields */
        .lg-label{ display:block; font-size:13px; font-weight:500; color:var(--ink); margin-bottom:6px; }
        .lg-field{
          width:100%; height:44px; padding:0 14px;
          border:1px solid var(--hairline); border-radius:10px;
          font-size:14px; font-family:inherit; color:var(--ink); background:#fff;
          outline:none; transition:border-color .15s var(--out), box-shadow .15s var(--out);
        }
        .lg-field::placeholder{ color:var(--muted-soft); }
        .lg-field:focus{ border-color:var(--red); box-shadow:0 0 0 3px var(--red-tint); }
        .lg-field-gap{ margin-bottom:16px; }

        /* Password reveal toggle — a control: instant icon swap, only press feedback. */
        .lg-pass-wrap{ position:relative; }
        .lg-pass-wrap .lg-field{ padding-right:44px; }
        .lg-eye{
          position:absolute; top:0; right:0; height:44px; width:42px;
          display:flex; align-items:center; justify-content:center;
          background:none; border:none; cursor:pointer; color:var(--muted-soft);
          transition:color .15s var(--out), transform .12s var(--out);
        }
        .lg-eye:hover{ color:var(--muted); }
        .lg-eye:active{ transform:scale(.9); }

        /* Inline helper — the recovery message. The shake just points at it. */
        .lg-help{ font-size:12.5px; color:var(--red); margin:6px 0 0; line-height:1.45; display:none; }

        /* Failed-login feedback: the canonical macOS shake, scoped to the field. */
        @keyframes lg-shake{
          0%,100%{ transform:translateX(0); }
          15%,45%,75%{ transform:translateX(-5px); }
          30%,60%,90%{ transform:translateX(5px); }
        }
        .lg-invalid .lg-field{ border-color:var(--red); animation:lg-shake .4s var(--ease-in-out); }
        .lg-invalid .lg-help{ display:block; }

        .lg-row{ display:flex; align-items:center; justify-content:space-between; margin:14px 0 22px; }
        .lg-remember{ display:flex; align-items:center; gap:8px; font-size:13px; color:var(--body); cursor:pointer; }
        .lg-remember input{ accent-color:var(--red); width:15px; height:15px; }
        .lg-link{ font-size:13px; color:var(--red); text-decoration:none; transition:opacity .15s; }
        .lg-link:hover{ opacity:.7; }

        /* Buttons */
        .lg-primary{
          width:100%; height:46px; border:none; border-radius:10px;
          background:var(--red); color:#fff; font-size:14px; font-weight:500; font-family:inherit;
          cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;
          transition:background .15s var(--out), transform .12s var(--out);
        }
        .lg-primary:hover{ background:var(--red-active); }
        .lg-primary:active{ transform:scale(.98); }
        .lg-primary svg{ transition:transform .2s var(--out); }
        .lg-primary:hover svg{ transform:translateX(2px); }

        .lg-ghost{
          width:100%; height:46px; border:1px solid var(--hairline); border-radius:10px;
          background:#fff; color:var(--ink); font-size:14px; font-family:inherit;
          cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;
          text-decoration:none; transition:border-color .15s var(--out), background .15s var(--out);
        }
        .lg-ghost:hover{ border-color:var(--muted-soft); background:var(--canvas); }
        .lg-ghost + .lg-ghost{ margin-top:10px; }

        /* Foot */
        .lg-foot{ font-size:13px; color:var(--muted); margin:22px 0 0; text-align:center; }
        .lg-foot a{ color:var(--red); text-decoration:none; font-weight:500; }
        .lg-demo{
          margin-top:14px; background:var(--canvas); border:1px dashed var(--hairline);
          border-radius:10px; padding:10px 12px; font-size:12px; color:var(--muted); text-align:center;
        }
        .lg-demo b{ color:var(--ink); font-weight:600; font-family:var(--font-mono); }
        .lg-protected{ font-size:11.5px; color:var(--muted); margin:18px 0 0; text-align:center; }

        .lg-under{ font-size:11.5px; color:var(--muted); margin-top:22px; text-align:center; }

        @media (prefers-reduced-motion: reduce){
          .lg-card{ animation:none; }
          .lg-primary svg{ transition:none; }
          /* Keep the red border + helper text; drop only the shake. */
          .lg-invalid .lg-field{ animation:none; }
        }
    </style>
</head>
<body>
{{-- Corner-stacks background — default login only (branded tenants get a plain canvas). --}}
@unless ($brandTenant)
<div class="lg-bg" aria-hidden="true">
  <span class="b b1"></span><span class="b b2"></span>
  <span class="b b3"></span><span class="b b4"></span>
</div>
@endunless
<div class="lg-stage">
  <form class="lg-card" action="/login" method="post">
    @csrf
    @if ($brandTenant)<input type="hidden" name="tenant_slug" value="{{ $brandTenant->slug }}">@endif

    {{-- Brand lockup --}}
    <div class="lg-brand">
        @if ($brandTenant && $brandLogo)
            <img src="{{ $brandLogo }}" alt="{{ $brandTenant->name }}" style="width:34px;height:34px;border-radius:9px;object-fit:cover;">
            <div class="lg-word">{{ $brandTenant->name }}</div>
        @elseif ($brandTenant)
            <div class="lg-logo" style="background:{{ $brandColor }};">{{ $brandTenant->initials }}</div>
            <div class="lg-word">{{ $brandTenant->name }}</div>
        @else
            <div class="lg-logo" style="background:var(--red);">A</div>
            <div class="lg-word">Amanah<span>ku</span></div>
        @endif
    </div>

    @include('partials.env-badge')

    <h1 class="lg-h1">Welcome back</h1>
    @if ($brandTenant)
        <p class="lg-sub">{{ $brandTenant->welcome_message ?: 'Sign in to your '.$brandTenant->name.' workspace.' }}</p>
    @else
        <p class="lg-sub">Sign in to continue to your workspace.</p>
    @endif

    {{-- Failed-login errors surface as the inline helper under the password field
         (see .lg-invalid below), not a second banner here. --}}

    @if (! $brandTenant && app()->isLocal())
    {{-- Quick login (demo) — local development only; never rendered in staging/production. --}}
    <div class="lg-quick-label">Quick login · demo</div>
    <div class="lg-quick-grid">
        <button type="button" class="lg-quick" onclick="quickLogin('hr.unijaya@gmail.com')"><b>HR</b><span>Unijaya HR</span></button>
        <button type="button" class="lg-quick" onclick="quickLogin('superadmin@amanahku.com')"><b>Super Admin</b><span>Platform console</span></button>
    </div>
    <p class="lg-quick-hint">One click signs you in. HR demo picks a workspace next.</p>

    <div class="lg-div"><span></span><em>or sign in</em><span></span></div>
    @endif

    <label class="lg-label" for="email">Work email</label>
    <input class="lg-field lg-field-gap" id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@company.com" autocomplete="username" required>

    <label class="lg-label" for="password">Password</label>
    <div class="lg-pass-wrap{{ $errors->any() ? ' lg-invalid' : '' }}" id="pass-wrap">
        <input class="lg-field" id="password" name="password" type="password" placeholder="••••••••" autocomplete="current-password" required>
        <button type="button" class="lg-eye" id="lg-eye" aria-label="Show password">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
        <p class="lg-help" id="pass-help">{{ $errors->first() }}</p>
    </div>

    <div class="lg-row">
        <label class="lg-remember"><input type="checkbox" name="remember" checked> Remember me</label>
        <a class="lg-link" href="{{ route('password.request') }}">Forgot password?</a>
    </div>

    <button type="submit" class="lg-primary">
        Sign in
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
    </button>

    <div class="lg-div"><span></span><em>or</em><span></span></div>

    <button type="button" id="passkey-signin" class="lg-ghost">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18v3h6v-3M12 2a5 5 0 0 0-5 5c0 2 1 3 1 3M12 2a5 5 0 0 1 5 5M12 7v.01M8 21l4-9 4 9"/></svg>
        Sign in with a passkey
    </button>
    <div id="passkey-error" style="display:none;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:9px;padding:9px 12px;margin-top:12px;"></div>

    @if (\App\Services\OidcClient::fromConfig()->configured())
        <a href="{{ route('oidc.redirect') }}" class="lg-ghost" style="margin-top:10px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Sign in with SSO
        </a>
    @endif

    @unless ($brandTenant)
        <p class="lg-foot">New to Amanahku? <a href="{{ route('register') }}">Create an account</a></p>
        @if (app()->isLocal())
            {{-- Demo credential hint — local development only. --}}
            <div class="lg-demo">Demo password — <b>password</b></div>
        @endif
    @else
        @if ($brandTenant->email || $brandTenant->contact_number)
            <p style="font-size:12.5px;color:var(--muted);margin-top:22px;text-align:center;">Need help signing in? Contact <span style="color:var(--ink);">{{ $brandTenant->email ?: $brandTenant->contact_number }}</span></p>
        @endif
    @endunless
    <p class="lg-protected">Protected by Amanahku · Multi-tenant SSO</p>
  </form>

  <p class="lg-under">© {{ date('Y') }} Amanahku</p>
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

    // Password reveal — instant swap (a control, not a moment).
    const pw = document.getElementById('password');
    const eye = document.getElementById('lg-eye');
    const EYE = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const EYE_OFF = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22M9.88 9.88a3 3 0 1 0 4.24 4.24"/></svg>';
    eye.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.innerHTML = show ? EYE_OFF : EYE;
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

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
