<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select workspace · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* ── Workspace picker — shares the login's visual world (cream canvas, corner
              stacks, red accent). Kept self-contained per-page, like auth/login. ── */
        :root{
          --out:cubic-bezier(.23,1,.32,1);
        }
        *{box-sizing:border-box;}
        html,body{margin:0;}
        body{
          font-family:var(--font-sans);
          color:var(--body);
          background:var(--canvas);
          -webkit-font-smoothing:antialiased;
          min-height:100vh;
        }

        /* Corner stacks — desktop only (mirrors auth/login). */
        .ts-bg{ position:fixed; inset:0; overflow:hidden; pointer-events:none; z-index:0; }
        .ts-bg .b{ position:absolute; }
        .ts-bg .b1{ left:0; bottom:0; width:clamp(120px,20vw,300px); height:clamp(150px,34vh,340px); background:var(--red); border-radius:0 14px 0 0; }
        .ts-bg .b2{ left:clamp(120px,20vw,300px); bottom:0; width:clamp(48px,7vw,110px); height:clamp(72px,18vh,180px); background:var(--red-active); }
        .ts-bg .b3{ right:0; top:0; width:clamp(130px,22vw,320px); height:clamp(130px,30vh,300px); background:var(--red-tint); border-radius:0 0 0 14px; }
        .ts-bg .b4{ right:0; top:0; width:clamp(46px,7vw,108px); height:clamp(60px,15vh,150px); background:var(--red); }

        .ts-stage{ position:relative; z-index:1; min-height:100vh; display:flex; flex-direction:column; align-items:center; padding:64px 24px 48px; }
        .ts-col{ width:100%; max-width:600px; }

        /* Brand lockup */
        .ts-brand{ display:flex; align-items:center; justify-content:center; gap:11px; margin-bottom:34px; }
        .ts-logo{ width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:17px; letter-spacing:-.02em; box-shadow:0 1px 2px rgba(214,35,43,.35); }
        .ts-word{ font-weight:600; font-size:18px; color:var(--ink); letter-spacing:-.02em; }
        .ts-word span{ color:var(--red); }

        /* Heading */
        .ts-h1{ font-size:25px; font-weight:600; color:var(--ink); letter-spacing:-.025em; margin:0 0 6px; line-height:1.15; text-align:center; }
        .ts-sub{ font-size:14px; color:var(--muted); margin:0 0 32px; line-height:1.5; text-align:center; }

        /* Verify-email banner */
        .ts-verify{ display:flex; align-items:center; gap:14px; margin-bottom:16px; background:#fbf6e9; border:1px solid #ecd9a6; border-radius:12px; padding:14px 18px; }
        .ts-verify p{ font-size:13.5px; color:#7a5d00; line-height:1.5; flex:1; margin:0; }
        .ts-verify button{ white-space:nowrap; height:38px; padding:0 16px; font-size:13px; font-weight:600; background:#fff; border:1px solid #ecd9a6; border-radius:9px; color:#7a5d00; cursor:pointer; font-family:inherit; transition:background .15s var(--out); }
        .ts-verify button:hover{ background:#fdfaf1; }

        /* Empty state */
        .ts-empty{ background:#fff; border:1px solid var(--hairline); border-radius:14px; padding:40px 36px; text-align:center; box-shadow:0 4px 14px -6px rgba(38,37,30,.10); }
        .ts-empty-ic{ width:52px; height:52px; border-radius:13px; background:var(--hairline-soft); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; color:var(--muted); }
        .ts-empty h2{ font-weight:600; font-size:18px; color:var(--ink); margin:0 0 8px; letter-spacing:-.01em; }
        .ts-empty p{ font-size:14px; color:var(--muted); line-height:1.6; margin:0; }

        /* Workspace rows */
        .ts-list{ display:flex; flex-direction:column; gap:12px; }
        .ts-row{
          display:flex; align-items:center; gap:16px;
          padding:18px 20px; background:#fff; border:1px solid var(--hairline);
          border-radius:13px; text-decoration:none;
          box-shadow:0 3px 10px -7px rgba(38,37,30,.14);
          transition:border-color .16s var(--out), transform .16s var(--out), box-shadow .16s var(--out);
        }
        .ts-row:hover{ border-color:var(--muted-soft); transform:translateY(-2px); box-shadow:0 10px 24px -14px rgba(38,37,30,.30); }
        .ts-row:active{ transform:translateY(0) scale(.995); }
        .ts-row-admin{ border-style:dashed; border-color:var(--red); }
        .ts-row-admin:hover{ border-color:var(--red-active); }

        .ts-ava{ width:46px; height:46px; border-radius:11px; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; flex-shrink:0; }
        .ts-body{ flex:1; min-width:0; }
        .ts-name{ font-weight:600; font-size:15px; color:var(--ink); letter-spacing:-.01em; }
        .ts-meta{ font-size:12.5px; color:var(--muted); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ts-plan{ font-size:11px; font-weight:600; color:var(--muted); background:var(--hairline-soft); padding:4px 10px; border-radius:9999px; text-transform:capitalize; flex-shrink:0; }
        .ts-chev{ color:var(--muted-soft); flex-shrink:0; transition:transform .2s var(--out), color .2s var(--out); }
        .ts-row:hover .ts-chev{ transform:translateX(3px); color:var(--red); }

        /* Footer */
        .ts-foot{ margin-top:28px; display:flex; align-items:center; justify-content:center; gap:20px; }
        .ts-foot a{ font-size:13px; color:var(--muted); text-decoration:none; transition:color .15s var(--out); }
        .ts-foot a:hover{ color:var(--ink); }
        .ts-foot button{ font-size:13px; font-weight:600; color:var(--red); background:none; border:0; cursor:pointer; padding:0; font-family:inherit; transition:opacity .15s var(--out); }
        .ts-foot button:hover{ opacity:.7; }

        /* One authored moment: the rows settle in, top to bottom. */
        @keyframes ts-in{ from{ opacity:0; transform:translateY(9px); } to{ opacity:1; transform:none; } }
        .ts-stagger{ animation:ts-in .5s var(--out) both; animation-delay:calc(var(--i,0) * 60ms); }

        @media (max-width:640px){
          /* Corner stacks are a desktop treatment — see auth/login for the rationale. */
          .ts-bg{ display:none; }
          .ts-stage{ padding:44px 20px 40px; }
          .ts-verify{ flex-direction:column; align-items:stretch; }
          /* Plan tier is secondary in a picker — drop it so the name gets the width. */
          .ts-plan{ display:none; }
        }
        @media (prefers-reduced-motion: reduce){
          .ts-stagger{ animation:none; }
          .ts-row{ transition:border-color .16s var(--out); }
          .ts-row:hover{ transform:none; }
          .ts-chev{ transition:color .2s var(--out); }
          .ts-row:hover .ts-chev{ transform:none; }
        }
    </style>
@include('partials.pwa-head')
</head>
<body>
{{-- Corner-stacks background — desktop only. --}}
<div class="ts-bg" aria-hidden="true">
  <span class="b b1"></span><span class="b b2"></span>
  <span class="b b3"></span><span class="b b4"></span>
</div>

<div class="ts-stage">
  <div class="ts-col">
    <div class="ts-brand">
      <div class="ts-logo">A</div>
      <div class="ts-word">Amanah<span>ku</span></div>
    </div>

    <h1 class="ts-h1">Select a workspace</h1>
    <p class="ts-sub">Choose a workspace to continue.</p>

    @unless (auth()->user()->hasVerifiedEmail())
      <div class="ts-verify">
        <p>Please verify your email to secure your account. Check your inbox for the link.</p>
        <form method="POST" action="{{ route('verification.send') }}">
          @csrf
          <button type="submit">Resend link</button>
        </form>
      </div>
    @endunless

    @if ($tenants->isEmpty() && ! auth()->user()->isSuperAdmin())
      <div class="ts-empty">
        <div class="ts-empty-ic">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M6 21V7l6-4 6 4v14M10 9h.01M14 9h.01M10 13h.01M14 13h.01M10 17h.01M14 17h.01"/></svg>
        </div>
        <h2>No workspace yet</h2>
        <p>Your account is ready, but it isn't linked to a company. Ask your HR admin to add you with this email, or contact the Amanahku team to get set up.</p>
      </div>
    @endif

    <div class="ts-list">
      @if (auth()->user()->isSuperAdmin())
        <a href="{{ route('superadmin.companies.index') }}" class="ts-row ts-row-admin ts-stagger" style="--i:0;">
          <div class="ts-ava" style="background:var(--red);">★</div>
          <div class="ts-body">
            <div class="ts-name">Super Admin Console</div>
            <div class="ts-meta">Provision and manage companies across the platform</div>
          </div>
          <svg class="ts-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      @endif

      @foreach ($tenants as $i => $t)
        <a href="{{ route('tenant.enter', $t) }}" class="ts-row ts-stagger" style="--i:{{ $i + (auth()->user()->isSuperAdmin() ? 1 : 0) }};">
          <div class="ts-ava" style="background:{{ $t->color }};">{{ $t->initials }}</div>
          <div class="ts-body">
            <div class="ts-name">{{ $t->name }}</div>
            <div class="ts-meta">{{ $t->metaLine }} · {{ ucfirst($t->pivot?->role ?? 'super admin') }}</div>
          </div>
          <span class="ts-plan">{{ $t->plan }}</span>
          <svg class="ts-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      @endforeach
    </div>

    <div class="ts-foot">
      <a href="#">+ Request access to another company</a>
      <form action="/logout" method="post" style="display:inline;">
        @csrf
        <button type="submit">Sign out</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
