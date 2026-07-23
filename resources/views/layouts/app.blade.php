<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Amanahku' }} · Amanahku</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php $embed = $embed ?? false; @endphp
<div x-data="{ ai: false, nav: false, kb: @js((bool) old('kbform')), kbView: @js(old('kbform') ?: 'feed'), msg: false }" style="{{ $embed ? 'background:var(--canvas);' : 'display:flex;height:100vh;overflow:hidden;background:var(--canvas);' }}">

    @unless ($embed)
        @include('partials.sidebar')
        <div class="uj-nav-backdrop" x-show="nav" x-cloak @click="nav = false"></div>
    @endunless

    <div style="{{ $embed ? 'min-width:0;' : 'flex:1;display:flex;flex-direction:column;min-width:0;height:100vh;' }}">
        @unless ($embed)
        @include('partials.header')

        {{-- Subheader: breadcrumb + title + persona toggle --}}
        <div class="uj-subhead" style="flex-shrink:0;background:#fff;border-bottom:1px solid var(--hairline);padding:16px 28px 18px;">
            <div style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted);margin-bottom:7px;">
                @foreach ($crumbs as $i => $crumb)
                    <span style="color:{{ $i === count($crumbs) - 1 ? 'var(--ink)' : 'var(--muted)' }};"
                          x-data="{ en: @js($crumb), ms: @js($crumbsMs[$i] ?? $crumb) }"
                          x-text="$store.ui.lang==='en' ? en : ms">{{ $crumb }}</span>
                    @if ($i < count($crumbs) - 1)
                        <span style="color:var(--muted-soft);">/</span>
                    @endif
                @endforeach
            </div>
            <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div x-data="{ t: { en: @js($pageTitle), ms: @js($pageTitleMs) }, s: { en: @js($pageSub), ms: @js($pageSubMs) } }">
                    <h1 style="font-weight:400;font-size:25px;letter-spacing:-0.4px;color:var(--ink);margin:0;" x-text="t[$store.ui.lang] ?? t.en">{{ $pageTitle }}</h1>
                    <p style="font-size:13.5px;color:var(--muted);margin:5px 0 0;" x-text="s[$store.ui.lang] ?? s.en">{{ $pageSub }}</p>
                </div>
                @if ($showPersona)
                    <div style="display:flex;background:var(--canvas);border:1px solid var(--hairline);border-radius:9px;padding:3px;gap:2px;">
                        @foreach ($personas as $p)
                            @php $on = $persona === $p['id']; @endphp
                            <a href="{{ route('app.screen', ['screen' => $screen, 'persona' => $p['id']]) }}"
                               style="padding:6px 13px;border-radius:7px;font-size:12.5px;font-weight:500;white-space:nowrap;text-decoration:none;color:{{ $on ? '#fff' : 'var(--body)' }};background:{{ $on ? 'var(--red)' : 'transparent' }};">{{ $p['label'] }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endunless

        {{-- Scrollable body --}}
        <main class="uj-main" style="{{ $embed ? 'padding:16px 18px 24px;' : 'flex:1;overflow-y:auto;padding:24px 28px 48px;' }}">
            <div class="uj-fade" style="width:100%;">
                @if (session('ok'))
                    <div x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:10px;background:#e7f4ee;border:1px solid var(--success);color:#176e51;font-size:13px;border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                        <span style="flex:1;">{{ session('ok') }}</span>
                        <button @click="show = false" style="color:#176e51;font-size:16px;">×</button>
                    </div>
                @endif
                @if (session('error'))
                    <div x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:10px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                        <span style="flex:1;">{{ session('error') }}</span>
                        <button @click="show = false" style="color:var(--red);font-size:16px;">×</button>
                    </div>
                @endif
                {{-- One-time password reveal after an HR password reset (MemberController::resetPassword).
                     Shown once, copyable; never persisted or logged. --}}
                @if (session('reset_password'))
                    @php $rp = session('reset_password'); @endphp
                    <div x-data="{ show: true, copied: false, pw: @js($rp['password']) }" x-show="show"
                         style="background:#fff8ec;border:1px solid #e0a94a;color:#7a5314;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                        <div style="display:flex;align-items:flex-start;gap:10px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-top:2px;flex-shrink:0;"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;font-size:13px;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'One-time password for {{ $rp['name'] }}' : 'Kata laluan sekali guna untuk {{ $rp['name'] }}'"></span></div>
                                <p style="font-size:11.5px;margin:0 0 9px;color:#8a6a2e;"><span x-text="$store.ui.lang==='en' ? 'Shown once — copy it now and give it to them. They must set their own password on next sign-in.' : 'Dipaparkan sekali sahaja — salin sekarang dan berikan kepada mereka. Mereka mesti menetapkan kata laluan sendiri semasa log masuk seterusnya.'"></span></p>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <code style="font-family:var(--font-mono);font-size:14px;font-weight:600;background:#fff;border:1px solid #e0a94a;border-radius:7px;padding:7px 11px;letter-spacing:0.5px;user-select:all;">{{ $rp['password'] }}</code>
                                    <button type="button" @click="navigator.clipboard.writeText(pw); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="uj-btn-ghost" style="height:34px;font-size:12px;padding:0 12px;">
                                        <span x-show="!copied" x-text="$store.ui.lang==='en' ? 'Copy' : 'Salin'">Copy</span>
                                        <span x-show="copied" x-cloak x-text="$store.ui.lang==='en' ? 'Copied' : 'Disalin'"></span>
                                    </button>
                                </div>
                            </div>
                            <button @click="show = false" style="color:#7a5314;font-size:16px;flex-shrink:0;">×</button>
                        </div>
                    </div>
                @endif
                @if (($qaTsOverdue ?? false))
                    <div x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:10px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                        <span style="flex:1;" x-text="$store.ui.lang==='en'
                            ? 'Your timesheet for this week is overdue. Fill every working day to 100%.'
                            : 'Timesheet anda untuk minggu ini sudah lewat. Isi setiap hari bekerja ke 100%.'">Your timesheet for this week is overdue. Fill every working day to 100%.</span>
                        <a href="{{ route('app.screen', 'timesheets') }}" style="white-space:nowrap;font-weight:600;text-decoration:underline;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Update now' : 'Kemas kini'">Update now</a>
                        <button @click="show = false" style="color:var(--red);font-size:16px;">×</button>
                    </div>
                @endif
                @if (($profileCompletion ?? null) && ! $profileCompletion['complete'] && $screen !== 'welcome')
                    <div x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:11px;background:#fff;border:1px solid var(--hairline);border-left:3px solid var(--red);border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <div style="flex:1;">
                            <div style="font-size:13px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Finish your profile — {{ $profileCompletion['pct'] }}% complete' : 'Lengkapkan profil anda — {{ $profileCompletion['pct'] }}% siap'">Finish your profile — {{ $profileCompletion['pct'] }}% complete</div>
                            <div class="uj-progress" style="margin-top:6px;max-width:260px;"><span style="width:{{ $profileCompletion['pct'] }}%;background:var(--red);"></span></div>
                        </div>
                        <a href="{{ route('welcome.show') }}" style="white-space:nowrap;font-size:12.5px;font-weight:600;text-decoration:underline;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Complete now' : 'Lengkapkan'">Complete now</a>
                        <button @click="show = false" style="color:var(--muted);font-size:16px;">×</button>
                    </div>
                @endif
                @yield('screen')
            </div>
        </main>
    </div>

    @unless ($embed)
        @if ($aiEnabled ?? true)
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
            send() {
                const text = this.body.trim();
                if (!text || this.sending || !this.active) return;
                this.sending = true;
                const p = new URLSearchParams();
                p.append('body', text);
                if (this.active.conversationId) p.append('conversation_id', this.active.conversationId);
                else if (this.active.to) p.append('to', this.active.to);
                fetch('{{ route('messages.send') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: p.toString(),
                }).then(r => r.json()).then(d => {
                    if (d.ok) {
                        this.active.conversationId = d.conversationId;
                        this.active.messages.push(d.message);
                        this.body = '';
                        this.scrollDown();
                    }
                }).catch(() => {}).finally(() => { this.sending = false; });
            },
            back() { this.view = 'list'; this.active = null; this.body = ''; },
            scrollDown() { this.$nextTick(() => { if (this.$refs.scroll) this.$refs.scroll.scrollTop = this.$refs.scroll.scrollHeight; }); },
        }));
        @endif
    });
</script>
@include('partials.toast-host')
</body>
</html>
