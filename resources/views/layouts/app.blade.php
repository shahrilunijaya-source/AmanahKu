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
    @include('partials.pwa-head')
    {{-- Reveals the mobile-only camera-capture trigger in the messages composer (side panel is
         global, so this lives in the layout rather than a single screen). --}}
    <style>@media (hover: none) and (pointer: coarse) { .uj-cam-only { display:inline-flex !important; } }</style>
</head>
<body>
@php
    $embed = $embed ?? false;
    // Notices that hang off the header rather than scrolling with the page.
    $hasPins = ! $embed && (session('reset_password') || ($qaTsOverdue ?? false));
@endphp
<div x-data="{ ai: false, kb: @js((bool) old('kbform')), kbView: @js(old('kbform') ?: 'feed'), msg: false,
        sbCollapsed: localStorage.getItem('amanahku-sb-collapsed') === '1',
        toggleSb() {
            this.sbCollapsed = !this.sbCollapsed;
            localStorage.setItem('amanahku-sb-collapsed', this.sbCollapsed ? '1' : '0');
        },
        /* Which sidebar layout: 'tree' (every section listed down the column, its
           screens expanding in place on hover) or 'sections' (rows are sections and
           the screens open in a panel beside them). The tree is the default — it is
           the layout people already know. Follows the browser, same as the collapse
           state above, not the account, so a different machine starts on the default. */
        sbStyle: localStorage.getItem('amanahku-sb-style') === 'sections' ? 'sections' : 'tree',
        toggleSbStyle() {
            this.sbStyle = this.sbStyle === 'tree' ? 'sections' : 'tree';
            localStorage.setItem('amanahku-sb-style', this.sbStyle);
        } }"
     @keydown.window.ctrl.b.prevent="toggleSb()" @keydown.window.meta.b.prevent="toggleSb()"
     :class="{ 'uj-sb-collapsed': sbCollapsed, 'uj-sb-tree': sbStyle === 'tree' }"
     {{-- No bottom dock inside an embedded panel, so nothing there should reserve
          room for one (see --uj-dock-h in app.css). --}}
     style="{{ $embed ? '--uj-dock-h:0px;background:var(--canvas);' : 'display:flex;height:100vh;overflow:hidden;background:var(--canvas);' }}">

    @unless ($embed)
        @include('partials.sidebar')
        @include('partials.mobile-dock')
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
        @endunless

        {{-- ── Pinned band ────────────────────────────────────────────────────
             Two notices sit here instead of in the scrolling head stack, because
             losing either one off-screen costs real work: a one-time password is
             shown ONCE (scroll past it, refresh, and HR has to reset the account
             again), and an overdue timesheet is a deadline you are already late for.

             Nothing else pins. The profile nudge and the two opt-in prompts have no
             deadline and are dismissible, so pinning them would only nag.

             A sibling of <main>, not a child of it: that makes the band exactly as
             wide as the header (inside <main> it stopped at the scrollbar) and it
             never scrolls at all, so no sticky is involved. It takes flow space, so
             <main> simply gets the room that is left — hence .uj-main--pinned below,
             which drops the header clearance <main> would otherwise carry.

             Each notice renders as a .uj-pin-bar: edge to edge, square corners, part
             of the chrome rather than a card floating in the page. --}}
        @if ($hasPins)
            <div class="uj-head-pins">
            {{-- One-time password reveal after an HR password reset (MemberController::resetPassword).
                 Shown once, copyable; never persisted or logged. --}}
            @if (session('reset_password'))
                @php $rp = session('reset_password'); @endphp
                <div x-data="{ show: true, copied: false, pw: @js($rp['password']) }" x-show="show"
                     class="uj-pin-bar" style="background:#fff8ec;border:1px solid #e0a94a;color:#7a5314;">
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
            @endif
            @if (($qaTsOverdue ?? false))
                <div x-data="{ show: true }" x-show="show" x-transition.opacity.duration.150ms
                     class="uj-alert uj-pin-bar" data-tone="error" role="alert">
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
            </div>
        @endif


        {{-- Scrollable body. The page title block lives INSIDE it now: it used to be
             a fixed white band between two other white bands, and it says nothing
             worth keeping on screen once you have started reading. --}}
        @php
            // Shared page measure (see .uj-measured / .uj-main--wide in app.css). Every
            // non-embed screen centres in one column: focused screens at 920px, data-dense
            // screens (tables, boards, the org canvas) in a wider centred cap.
            $wideScreens = ['directory', 'team-board', 'staff-load', 'reports',
                'roles', 'calendar', 'attendance-admin', 'attendance-report',
                'messages', 'orgchart', 'board'];
            $isWide = ! $embed && in_array($screen ?? null, $wideScreens, true);
        @endphp
        <main class="uj-main {{ $embed ? '' : 'uj-measured' }} {{ $isWide ? 'uj-main--wide' : '' }} {{ $hasPins ? 'uj-main--pinned' : '' }}" style="{{ $embed ? 'padding:16px 18px 24px;' : 'flex:1;overflow-y:auto;padding:0 28px 48px;' }}">
            <div class="uj-head-stack {{ $embed ? 'uj-head-stack--embed' : '' }}">
                {{-- The install and alert-opt-in banners live INSIDE the head stack, not as
                     siblings of <main>. The header is position:absolute, so it takes no flow
                     space: a banner placed above <main> started at y=0 and the opaque header
                     painted over its top 56px, swallowing the title line and eating the taps.
                     The stack's own padding-top is the header clearance, and its gap gives the
                     spacing, so the banners now sit fully visible with the profile banner. --}}
                @unless ($embed)
                    @include('partials.ios-install')
                    @include('partials.enable-alerts')
                @endunless
                {{-- Flash confirmations are not rendered here: they are pushed into the
                     global toast queue on boot (see the toast seed in the Alpine block below). --}}
                @if (($profileCompletion ?? null) && ! $profileCompletion['complete'] && $screen !== 'welcome')
                    <div x-data="{ show: (() => { const t = localStorage.getItem('profileBannerDismissedUntil'); return !t || Date.now() > +t; })() }" x-show="show" x-cloak class="uj-banner-row" style="background:#fff;border:1px solid var(--hairline);border-radius:10px;padding:11px 16px;">
                        <span class="uj-stamp" data-tone="red" x-text="$store.ui.lang==='en' ? 'Incomplete' : 'Belum lengkap'">Incomplete</span>
                        <div class="uj-banner-text" style="flex:1;">
                            <div style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Finish your profile — {{ $profileCompletion['pct'] }}% complete' : 'Lengkapkan profil anda — {{ $profileCompletion['pct'] }}% siap'">Finish your profile — {{ $profileCompletion['pct'] }}% complete</div>
                            <div class="uj-progress" style="margin-top:6px;max-width:260px;"><span style="width:{{ $profileCompletion['pct'] }}%;background:var(--red);"></span></div>
                        </div>
                        <a href="{{ route('welcome.show') }}" style="white-space:nowrap;font-size:var(--t-sm);font-weight:600;text-decoration:underline;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Complete now' : 'Lengkapkan'">Complete now</a>
                        <button @click="show = false; localStorage.setItem('profileBannerDismissedUntil', Date.now() + 12*36e5)" style="color:var(--muted);font-size:var(--t-lg);">×</button>
                    </div>
                @endif
                @unless ($embed)
                    <div class="uj-pagehead">
                        <div>
                            {{-- The dashboard renders its own heading in the screen body, beside
                                 its chips and Me/Company switch, so it opts out of the page head
                                 entirely.

                                 The breadcrumb that used to sit above the heading is gone from
                                 every screen. Its last segment was always the same word as the
                                 <h1> directly beneath it ("Unijaya Resources / Messages" over a
                                 heading reading "Messages"), and the sidebar already marks where
                                 you are. --}}
                            @unless ($screen === 'dash')
                                <div x-data="{ t: { en: @js($pageTitle), ms: @js($pageTitleMs) }, s: { en: @js($pageSub), ms: @js($pageSubMs) } }">
                                    <h1 x-text="t[$store.ui.lang] ?? t.en">{{ $pageTitle }}</h1>
                                    <p x-text="s[$store.ui.lang] ?? s.en">{{ $pageSub }}</p>
                                </div>
                            @endunless
                        </div>
                    </div>
                @endunless
            </div>
            <div class="uj-fade" style="width:100%;">
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
        @include('partials.ticket-raise')
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
        // Direct-messages unread badge + the slide-over panel's thread list, both seeded
        // server-side then refreshed by a 5-7s poll — one shared store so the envelope
        // count and the panel's rows (snippet, order, per-thread unread) stay live even
        // when the panel is closed, instead of only catching up once it's reopened.
        //
        // Self-rescheduled with jitter and skipped while the tab is hidden, for the same
        // reasons spelled out on notifbell below: a fixed interval let every open tab hit
        // this route on the same tick, and background tabs kept polling forever. A profile
        // of production (2026-08-12) caught a background tab still calling this every 5s.
        Alpine.store('msgbadge', {
            unread: @js($msgUnread ?? 0),
            threads: @js($msgThreads ?? []),
            init() { this.schedule(); },
            schedule() { setTimeout(() => { this.poll(); this.schedule(); }, 5000 + Math.random() * 2000); },
            poll() {
                if (document.hidden) return;
                fetch('{{ route('messages.summary') }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => { this.unread = d.unread; this.threads = d.threads; }).catch(() => {});
            },
        });
        @endif

        // Header bell unread badge + dropdown list. Seeded server-side (from the same
        // AppServiceProvider composer that renders the first paint), then refreshed by
        // a 15-20s poll (self-rescheduled with jitter, not a fixed setInterval) — slower
        // than msgbadge's 5s since HR events aren't chat-speed urgent. Full-replace, no
        // cursor: same trade-off as msgbadge, simplest thing that stays correct even if
        // another tab or "Mark all read" changed state.
        //
        // The jitter and the document.hidden skip in poll() below both exist because a
        // fixed 15s interval let many tabs land on this route at the same instant, and
        // concurrent hits on the same rate-limit key deadlocked the DB-backed cache store
        // in production (SQLSTATE[40001] 1213 on `cache`, 2026-08-10).
        Alpine.store('notifbell', {
            unread: @js($unreadCount ?? 0),
            notifications: @js($notifications ?? []),
            init() { this.schedule(); },
            schedule() { setTimeout(() => { this.poll(); this.schedule(); }, 15000 + Math.random() * 5000); },
            poll() {
                if (document.hidden) return;
                fetch('{{ route('notifications.summary') }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => { this.unread = d.unread; this.notifications = d.notifications; }).catch(() => {});
            },
            /** Fired on click, alongside the link's own navigation — not blocking it. */
            markOne(id) {
                const n = this.notifications.find(n => n.id === id);
                if (! n || n.read_at) return;
                n.read_at = true;
                this.unread = Math.max(0, this.unread - 1);
                // keepalive: the click's own href navigates away right after this fires,
                // which would otherwise abort the request mid-flight.
                fetch(`/app/notifications/${id}/read`, {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                }).catch(() => {});
            },
        });

        @if ($msgEnabled ?? false)

        // Slide-over messages: a list of conversations, and the SAME thread fragment the
        // full screen renders, fetched from messages.pane. This component used to carry
        // its own bubbles, composer, attachment tiles and send logic — a second
        // implementation that had already fallen behind on run grouping, day dividers and
        // read receipts. Only the glue lives here now.
        //
        // It deliberately does NOT touch history: the panel floats over whatever screen
        // you are on, and rewriting the URL would strand you somewhere else on refresh.
        Alpine.data('messagesPanel', () => ({
            view: 'list',
            // Read from the shared store (kept live by its own 5s poll) rather than a
            // local copy, so the row list is already fresh the moment the panel opens —
            // no separate poll to duplicate here.
            get threads() { return this.$store.msgbadge.threads; },
            activeId: null,
            loading: false,
            sending: false,
            error: '',
            lastMessageId: 0,
            pollTimer: null,
            csrf() { return document.querySelector('meta[name=csrf-token]').content; },

            /** Fetch the thread fragment and drop it into the panel's pane. */
            async swap(query) {
                this.loading = true;
                this.error = '';
                try {
                    const res = await fetch('{{ route('messages.pane') }}?' + query, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (! res.ok) { throw new Error('pane ' + res.status); }
                    this.$refs.pane.innerHTML = await res.text();
                    const id = new URLSearchParams(query).get('c');
                    this.activeId = id ? Number(id) : null;
                } catch (e) {
                    // Losing the panel is not worth a navigation — send them to the real
                    // screen, which is where a broken fragment can be recovered.
                    window.location = '{{ route('app.screen', 'messages') }}?' + query;
                } finally {
                    this.loading = false;
                }
            },

            open(t) {
                this.view = 'thread';
                if (t.unread > 0) { t.unread = 0; }
                return this.swap('c=' + t.id);
            },

            /** The fragment's back button. In the panel that means the conversation list. */
            back() { this.view = 'list'; this.activeId = null; },

            /* ── Live thread poll ─────────────────────────────────────────────
                Same idea as the full messages screen: check the open thread's
                newest id every few seconds, only re-swap the pane when it
                actually changed. */
            schedulePoll() {
                clearTimeout(this.pollTimer);
                this.pollTimer = setTimeout(() => this.pollThread(), 4000);
            },
            async pollThread() {
                // x-if tears the panel down when it's closed and rebuilds it fresh next
                // open — a plain setTimeout chain outlives that, so it must check its own
                // element is still attached instead of running forever in the background.
                if (! this.$el.isConnected) { return; }
                if (this.view !== 'thread' || ! this.activeId || document.hidden) { this.schedulePoll(); return; }
                try {
                    const res = await fetch('/app/messages/thread/' + this.activeId, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) {
                        const data = await res.json();
                        const newest = data.messages.length ? data.messages[data.messages.length - 1].id : 0;
                        if (newest !== this.lastMessageId) {
                            this.lastMessageId = newest;
                            await this.swap('c=' + this.activeId);
                        }
                    }
                } catch (e) {
                    // A missed poll just retries next tick.
                }
                this.schedulePoll();
            },

            init() { this.schedulePoll(); },

            /** The fragment's composer posts through here. */
            async send(form) {
                if (this.sending) { return; }
                this.sending = true;
                this.error = '';
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const data = await res.json();
                    if (! res.ok) {
                        this.error = Object.values(data.errors ?? {}).flat()[0]
                            ?? (this.$store.ui.lang === 'en' ? 'Could not send that message.' : 'Tidak dapat menghantar mesej itu.');
                        return;
                    }
                    this.touch(data.conversationId, data.message);
                    this.lastMessageId = data.message.id;
                    await this.swap('c=' + data.conversationId);
                } catch (e) {
                    this.error = this.$store.ui.lang === 'en' ? 'Could not send that message.' : 'Tidak dapat menghantar mesej itu.';
                } finally {
                    this.sending = false;
                }
            },

            /** Keep the list row honest without refetching the feed. */
            touch(id, message) {
                const t = this.threads.find(t => t.id === id);
                if (! t) { return; }
                t.snippet = message.body !== '' ? message.body.slice(0, 120) : '📎 Attachment';
                t.lastMine = true;
                t.at = this.$store.ui.lang === 'en' ? 'just now' : 'sebentar tadi';
                t.unread = 0;
                this.$store.msgbadge.threads = [t, ...this.threads.filter(x => x.id !== id)];
            },
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
        {{-- Neither a success nor a failure: the request was understood and declined,
             e.g. a second clock-in on a day already punched. A green tick there reads as
             "punched again", which is exactly what it did not do. --}}
        @if (session('info'))
        Alpine.store('toast').info(@js(session('info')));
        @endif
    });
</script>
@include('partials.toast-host')
</body>
</html>
