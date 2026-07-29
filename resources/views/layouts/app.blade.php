<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Amanahku' }} · Amanahku</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Installable web app. On iOS this is the only route to notifications at all:
         Safari shows them just for a web app added to the Home Screen. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#d6232b">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Amanahku">
    {{-- Reveals the mobile-only camera-capture trigger in the messages composer (side panel is
         global, so this lives in the layout rather than a single screen). --}}
    <style>@media (hover: none) and (pointer: coarse) { .uj-cam-only { display:inline-flex !important; } }</style>
</head>
<body>
@php $embed = $embed ?? false; @endphp
<div x-data="{ ai: false, nav: false, kb: @js((bool) old('kbform')), kbView: @js(old('kbform') ?: 'feed'), msg: false,
        sbCollapsed: localStorage.getItem('amanahku-sb-collapsed') === '1',
        toggleSb() {
            if (window.innerWidth <= 900) { this.nav = !this.nav; return; }
            this.sbCollapsed = !this.sbCollapsed;
            localStorage.setItem('amanahku-sb-collapsed', this.sbCollapsed ? '1' : '0');
        } }"
     @keydown.window.ctrl.b.prevent="toggleSb()" @keydown.window.meta.b.prevent="toggleSb()"
     :class="sbCollapsed ? 'uj-sb-collapsed' : ''"
     style="{{ $embed ? 'background:var(--canvas);' : 'display:flex;height:100vh;overflow:hidden;background:var(--canvas);' }}">

    @unless ($embed)
        @include('partials.sidebar')
        <div class="uj-nav-backdrop" x-show="nav" x-cloak @click="nav = false"></div>
    @endunless

    <div class="uj-shell-main" style="{{ $embed ? 'min-width:0;' : 'flex:1;display:flex;flex-direction:column;min-width:0;height:100vh;position:relative;' }}">
        @unless ($embed)
        @include('partials.header')
        {{-- Content scrolls under the header and dissolves into the page canvas
             here, rather than meeting a border. See .uj-hd-fade in app.css.
             The blur is inline, not in that rule: Lightning CSS (Tailwind v4)
             rewrites a `backdrop-filter` declaration in app.css to the -webkit-
             prefix alone and drops the standard property, which the browsers we
             target do not implement. Inline styles skip that pass. --}}
        <div class="uj-hd-fade" style="backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);"></div>
        @include('partials.ios-install')
        @include('partials.enable-alerts')
        @endunless

        {{-- Scrollable body. The page title block lives INSIDE it now: it used to be
             a fixed white band between two other white bands, and it says nothing
             worth keeping on screen once you have started reading. --}}
        <main class="uj-main" style="{{ $embed ? 'padding:16px 18px 24px;' : 'flex:1;overflow-y:auto;padding:0 28px 48px;' }}">
            @unless ($embed)
                <div class="uj-pagehead">
                    <div>
                        {{-- The dashboard renders its own heading in the screen body, beside
                             its chips and Me/Company switch, so it opts out of the whole page
                             head here — breadcrumb included. The sidebar already marks where
                             you are, and "Tenant / Dashboard" above a greeting said nothing. --}}
                        @unless ($screen === 'dash')
                            <div style="display:flex;align-items:center;gap:7px;font-size:var(--t-sm);color:var(--muted);margin-bottom:8px;">
                                @foreach ($crumbs as $i => $crumb)
                                    <span style="color:{{ $i === count($crumbs) - 1 ? 'var(--ink)' : 'var(--muted)' }};"
                                          x-data="{ en: @js($crumb), ms: @js($crumbsMs[$i] ?? $crumb) }"
                                          x-text="$store.ui.lang==='en' ? en : ms">{{ $crumb }}</span>
                                    @if ($i < count($crumbs) - 1)
                                        <span style="color:var(--muted);">/</span>
                                    @endif
                                @endforeach
                            </div>
                            <div x-data="{ t: { en: @js($pageTitle), ms: @js($pageTitleMs) }, s: { en: @js($pageSub), ms: @js($pageSubMs) } }">
                                <h1 x-text="t[$store.ui.lang] ?? t.en">{{ $pageTitle }}</h1>
                                <p x-text="s[$store.ui.lang] ?? s.en">{{ $pageSub }}</p>
                            </div>
                        @endunless
                    </div>
                </div>
            @endunless
            <div class="uj-fade" style="width:100%;">
                {{-- Flash confirmations are not rendered here: they are pushed into the
                     global toast queue on boot (see the toast seed in the Alpine block below). --}}
                {{-- One-time password reveal after an HR password reset (MemberController::resetPassword).
                     Shown once, copyable; never persisted or logged. --}}
                @if (session('reset_password'))
                    @php $rp = session('reset_password'); @endphp
                    <div x-data="{ show: true, copied: false, pw: @js($rp['password']) }" x-show="show"
                         style="background:#fff8ec;border:1px solid #e0a94a;color:#7a5314;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:2px;flex-shrink:0;"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;font-size:var(--t-base);margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'One-time password for {{ $rp['name'] }}' : 'Kata laluan sekali guna untuk {{ $rp['name'] }}'"></span></div>
                                <p style="font-size:var(--t-micro);margin:0 0 9px;color:#8a6a2e;"><span x-text="$store.ui.lang==='en' ? 'Shown once — copy it now and give it to them. They must set their own password on next sign-in.' : 'Dipaparkan sekali sahaja — salin sekarang dan berikan kepada mereka. Mereka mesti menetapkan kata laluan sendiri semasa log masuk seterusnya.'"></span></p>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <code style="font-family:var(--font-mono);font-size:var(--t-base);font-weight:600;background:#fff;border:1px solid #e0a94a;border-radius:7px;padding:7px 11px;letter-spacing:0.5px;user-select:all;">{{ $rp['password'] }}</code>
                                    <button type="button" @click="navigator.clipboard.writeText(pw); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="uj-btn-ghost" style="height:34px;font-size:var(--t-sm);padding:0 12px;">
                                        <span x-show="!copied" x-text="$store.ui.lang==='en' ? 'Copy' : 'Salin'">Copy</span>
                                        <span x-show="copied" x-cloak x-text="$store.ui.lang==='en' ? 'Copied' : 'Disalin'"></span>
                                    </button>
                                </div>
                                {{-- Whether the reset link also went out by email. HR needs this to
                                     know if relaying the password by hand is actually necessary. --}}
                                @if (($rp['mail'] ?? null) === 'sent')
                                    <p style="font-size:var(--t-micro);margin:9px 0 0;color:#8a6a2e;"><span x-text="$store.ui.lang==='en' ? 'A reset link was also emailed to {{ $rp['email'] ?? '' }}, so they can set their own password without this.' : 'Pautan tetapan semula juga dihantar ke {{ $rp['email'] ?? '' }}, jadi mereka boleh menetapkan kata laluan sendiri tanpa ini.'"></span></p>
                                @elseif (($rp['mail'] ?? null) === 'throttled')
                                    <p style="font-size:var(--t-micro);margin:9px 0 0;color:#8a6a2e;"><span x-text="$store.ui.lang==='en' ? 'A reset link was emailed recently, so another was not sent. Give them the password above.' : 'Pautan tetapan semula baru sahaja dihantar, jadi tiada yang baharu dihantar. Berikan kata laluan di atas kepada mereka.'"></span></p>
                                @elseif (($rp['mail'] ?? null) === 'failed')
                                    <p style="font-size:var(--t-micro);margin:9px 0 0;color:#a8501a;font-weight:600;"><span x-text="$store.ui.lang==='en' ? 'The reset email could not be sent. You must give them the password above.' : 'E-mel tetapan semula tidak dapat dihantar. Anda mesti berikan kata laluan di atas kepada mereka.'"></span></p>
                                @endif
                            </div>
                            <button @click="show = false" style="color:#7a5314;font-size:var(--t-lg);flex-shrink:0;">×</button>
                        </div>
                    </div>
                @endif
                @if (($qaTsOverdue ?? false))
                    <div x-data="{ show: true }" x-show="show" x-transition.opacity.duration.150ms
                         class="uj-alert" data-tone="error" role="alert">
                        <span class="uj-alert-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                        </span>
                        <span class="uj-alert-msg" x-text="$store.ui.lang==='en'
                            ? 'Your timesheet for this week is overdue. Fill every working day to 100%.'
                            : 'Timesheet anda untuk minggu ini sudah lewat. Isi setiap hari bekerja ke 100%.'">Your timesheet for this week is overdue. Fill every working day to 100%.</span>
                        <a href="{{ route('app.screen', 'timesheets') }}" class="uj-alert-action" x-text="$store.ui.lang==='en' ? 'Update now' : 'Kemas kini'">Update now</a>
                        <button type="button" class="uj-alert-close" @click="show = false"
                                :aria-label="$store.ui.lang === 'en' ? 'Dismiss' : 'Tutup'">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5.6 5.6l8.8 8.8M14.4 5.6l-8.8 8.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                @endif
                @if (($profileCompletion ?? null) && ! $profileCompletion['complete'] && $screen !== 'welcome')
                    <div x-data="{ show: (() => { const t = localStorage.getItem('profileBannerDismissedUntil'); return !t || Date.now() > +t; })() }" x-show="show" class="uj-banner-row" style="background:#fff;border:1px solid var(--hairline);border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <span class="uj-stamp" data-tone="red" x-text="$store.ui.lang==='en' ? 'Incomplete' : 'Belum lengkap'">Incomplete</span>
                        <div class="uj-banner-text" style="flex:1;">
                            <div style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Finish your profile — {{ $profileCompletion['pct'] }}% complete' : 'Lengkapkan profil anda — {{ $profileCompletion['pct'] }}% siap'">Finish your profile — {{ $profileCompletion['pct'] }}% complete</div>
                            <div class="uj-progress" style="margin-top:6px;max-width:260px;"><span style="width:{{ $profileCompletion['pct'] }}%;background:var(--red);"></span></div>
                        </div>
                        <a href="{{ route('welcome.show') }}" style="white-space:nowrap;font-size:var(--t-sm);font-weight:600;text-decoration:underline;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Complete now' : 'Lengkapkan'">Complete now</a>
                        <button @click="show = false; localStorage.setItem('profileBannerDismissedUntil', Date.now() + 12*36e5)" style="color:var(--muted);font-size:var(--t-lg);">×</button>
                    </div>
                @endif
                @yield('screen')
            </div>
        </main>
    </div>

    @unless ($embed)
        @if ($aiEnabled ?? false)
            @include('partials.ai-panel')
        @endif
        @if ($kbEnabled ?? false)
            @include('partials.knowledge-panel')
        @endif
        @if ($msgEnabled ?? false)
            @include('partials.messages-panel')
        @endif
        @include('partials.welcome')
        @include('partials.feedback')
    @endunless
</div>

@if ($embed)
    {{-- Report content height to the parent (Setup wizard) so the inline <iframe>
         can size itself to its screen — on load and whenever the content grows or
         shrinks (an "+ Add" form opening, a row deleting). Same-origin only. --}}
    <script>
        (function () {
            var post = function () {
                parent.postMessage({ type: 'embed-height', h: document.body.scrollHeight }, window.location.origin);
            };
            window.addEventListener('load', post);
            if (window.ResizeObserver) { new ResizeObserver(post).observe(document.body); }
            else { window.addEventListener('resize', post); }
        })();
    </script>
@endif

<script>
    // Global guidance language ('en' | 'ms'). Shared by every guide banner + field hint
    // so a user flips once and all on-screen help switches instantly. Runs before Alpine
    // initialises (this inline script is parsed before the deferred Vite module).
    // Mirror the saved language into a cookie so the server renders validation
    // errors in the same language as the in-app toggle. Runs on every load so
    // client + server stay in sync even before the first toggle this session.
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

        // What's New: the badge shows whenever the user hasn't seen the latest changelog
        // version. `latest` comes straight from config/changelog.php, so bumping the config
        // re-triggers the badge for everyone automatically. markSeen() clears it on view.
        Alpine.store('changelog', {
            latest: @js(config('changelog.releases.0.version')),
            seen: localStorage.getItem('amanahku-changelog-seen'),
            get unseen() { return this.latest && this.seen !== this.latest; },
            markSeen() {
                this.seen = this.latest;
                localStorage.setItem('amanahku-changelog-seen', this.latest);
            },
        });

        @if ($kbEnabled ?? false)
        // Knowledge Bank unread badge. Seeded server-side; cleared (with a fire-and-forget
        // read-receipt POST) the moment the user opens the panel.
        Alpine.store('kbadge', {
            unread: @js($kbUnread ?? 0),
            markRead() {
                if (this.unread === 0) return;
                this.unread = 0;
                fetch('{{ route('knowledge.read') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                }).catch(() => {});
            },
        });
        @endif

        @if ($msgEnabled ?? false)
        // Direct-messages unread badge. Seeded server-side, then refreshed by a ~30s poll
        // (a real server count over per-message read_at, so it also reflects messages the
        // other party sent since page load). init() runs automatically on registration.
        Alpine.store('msgbadge', {
            unread: @js($msgUnread ?? 0),
            init() { setInterval(() => this.poll(), 30000); },
            poll() {
                fetch('{{ route('messages.unread') }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => { this.unread = d.unread; }).catch(() => {});
            },
        });

        // Slide-over inline chat: list of conversations → open a thread (fetched) → send
        // without leaving the page. Seeded with the panel feed from context().
        Alpine.data('messagesPanel', (threads) => ({
            view: 'list',
            threads: threads,
            active: null,
            body: '',
            files: [],
            sending: false,
            loading: false,
            csrf() { return document.querySelector('meta[name=csrf-token]').content; },
            open(t) {
                this.view = 'thread';
                this.loading = true;
                this.active = { conversationId: t.id, to: t.other.id, other: t.other, messages: [] };
                fetch('{{ url('/app/messages/thread') }}/' + t.id, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => { if (d.ok) { this.active.messages = d.messages; this.active.other = d.other; this.scrollDown(); } })
                    .catch(() => {})
                    .finally(() => { this.loading = false; });
                if (t.unread > 0) { this.markRead(t.id); t.unread = 0; }
            },
            markRead(id) {
                fetch('{{ url('/app/messages') }}/' + id + '/read', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                }).then(r => r.json()).then(d => { if (this.$store.msgbadge) this.$store.msgbadge.unread = d.unread; }).catch(() => {});
            },
            addFiles(e) {
                this.files = this.files.concat(Array.from(e.target.files));
                e.target.value = '';
            },
            send() {
                const text = this.body.trim();
                if ((!text && this.files.length === 0) || this.sending || !this.active) return;
                this.sending = true;
                const fd = new FormData();
                fd.append('body', text);
                if (this.active.conversationId) fd.append('conversation_id', this.active.conversationId);
                else if (this.active.to) fd.append('to', this.active.to);
                this.files.forEach(f => fd.append('attachments[]', f));
                fetch('{{ route('messages.send') }}', {
                    method: 'POST',
                    // No Content-Type header — the browser sets the multipart boundary.
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                }).then(r => r.json()).then(d => {
                    if (d.ok) {
                        this.active.conversationId = d.conversationId;
                        this.active.messages.push(d.message);
                        this.body = '';
                        this.files = [];
                        this.scrollDown();
                    }
                }).catch(() => {}).finally(() => { this.sending = false; });
            },
            back() { this.view = 'list'; this.active = null; this.body = ''; this.files = []; },
            scrollDown() { this.$nextTick(() => { if (this.$refs.scroll) this.$refs.scroll.scrollTop = this.$refs.scroll.scrollHeight; }); },
        }));
        @endif

        // Server flash messages ride the same queue as client-side confirmations, so a
        // redirect result appears once, as a toast, instead of an in-page banner.
        @if (session('error'))
        Alpine.store('toast').error(@js(session('error')));
        @endif
        @if (session('ok'))
        Alpine.store('toast').success(@js(session('ok')));
        @endif
    });
</script>
@include('partials.toast-host')
</body>
</html>
