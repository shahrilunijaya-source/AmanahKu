<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Welcome' }} · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.pwa-head')
</head>
<body style="background:var(--canvas);min-height:100vh;">
{{-- Full-screen, no sidebar: a focused first-run surface. --}}
<div style="min-height:100vh;display:flex;flex-direction:column;">
    <header style="flex-shrink:0;background:var(--sidebar);color:#fff;padding:15px 28px;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;">A</span>
            <span style="font-weight:600;font-size:15px;letter-spacing:-.2px;">Amanahku</span>
        </div>
        <div style="display:flex;align-items:center;gap:14px;">
            {{-- Language toggle — mirrors the in-app guidance toggle. --}}
            <div style="display:flex;background:rgba(255,255,255,.1);border-radius:8px;padding:3px;gap:2px;">
                <button type="button" @click="$store.ui.setLang('en')"
                        :style="$store.ui.lang==='en' ? { background:'#fff', color:'var(--ink)' } : { background:'transparent', color:'#b8b6ad' }"
                        style="padding:4px 11px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">EN</button>
                <button type="button" @click="$store.ui.setLang('ms')"
                        :style="$store.ui.lang==='ms' ? { background:'#fff', color:'var(--ink)' } : { background:'transparent', color:'#b8b6ad' }"
                        style="padding:4px 11px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">BM</button>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" style="font-size:12.5px;color:#b8b6ad;background:transparent;cursor:pointer;"
                        x-data x-text="$store.ui.lang==='en' ? 'Sign out' : 'Log keluar'">Sign out</button>
            </form>
        </div>
    </header>

    <main style="flex:1;overflow-y:auto;padding:32px 16px 64px;">
        <div style="max-width:860px;margin:0 auto;">
            @if (session('ok'))
                <div x-data="{ show: true }" x-show="show" x-transition.opacity.duration.150ms
                     class="uj-alert" data-tone="success" role="status">
                    <span class="uj-alert-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                    </span>
                    <span class="uj-alert-msg">{{ session('ok') }}</span>
                    <button type="button" class="uj-alert-close" @click="show = false"
                            :aria-label="$store.ui.lang === 'en' ? 'Dismiss' : 'Tutup'">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5.6 5.6l8.8 8.8M14.4 5.6l-8.8 8.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </button>
                </div>
            @endif
            @if (session('error') || $errors->any())
                <div x-data="{ show: true }" x-show="show" x-transition.opacity.duration.150ms
                     class="uj-alert" data-tone="error" role="alert">
                    <span class="uj-alert-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                    </span>
                    <span class="uj-alert-msg">{{ session('error') ?? $errors->first() }}</span>
                    <button type="button" class="uj-alert-close" @click="show = false"
                            :aria-label="$store.ui.lang === 'en' ? 'Dismiss' : 'Tutup'">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5.6 5.6l8.8 8.8M14.4 5.6l-8.8 8.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    </button>
                </div>
            @endif

            @yield('screen')
        </div>
    </main>
</div>

<script>
    (function () {
        var l = localStorage.getItem('amanahku-lang') || 'en';
        document.cookie = 'amanahku-lang=' + l + ';path=/;max-age=31536000;samesite=lax';
    })();
    document.addEventListener('alpine:init', () => {
        Alpine.store('ui', {
            lang: localStorage.getItem('amanahku-lang') || 'en',
            setLang(l) {
                this.lang = l;
                localStorage.setItem('amanahku-lang', l);
                document.cookie = 'amanahku-lang=' + l + ';path=/;max-age=31536000;samesite=lax';
            },
        });
    });
</script>
@include('partials.toast-host')
</body>
</html>
