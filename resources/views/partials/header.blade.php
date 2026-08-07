<header class="uj-header">
    <button @click="toggleSb()" class="uj-nav-toggle uj-hd-ib" :aria-label="$store.ui.lang==='en' ? 'Open navigation' : 'Buka navigasi'">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"></path></svg>
    </button>
    <div x-data="{
            q: '',
            results: [],
            open: false,
            loading: false,
            timer: null,
            active: -1,
            search() {
                clearTimeout(this.timer);
                const term = this.q.trim();
                if (term === '') { this.results = []; this.open = false; this.loading = false; return; }
                this.loading = true;
                this.timer = setTimeout(() => {
                    fetch('{{ route('search.index') }}?q=' + encodeURIComponent(term), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.ok ? r.json() : [])
                    .then(d => { this.results = d; this.open = true; this.active = -1; })
                    .catch(() => { this.results = []; })
                    .finally(() => { this.loading = false; });
                }, 250);
            },
            go(emp) { window.location = '{{ url('/app/profile') }}?emp=' + emp; }
         }"
         @keydown.escape="open = false"
         @click.outside="open = false"
         class="uj-hd-search"
         style="flex:1;min-width:0;max-width:420px;position:relative;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><path d="M21 21l-4-4"></path></svg>
        <input x-model="q" @input="search()" @focus="if (results.length) open = true"
               @keydown.down.prevent="active = Math.min(active + 1, results.length - 1)"
               @keydown.up.prevent="active = Math.max(active - 1, -1)"
               @keydown.enter.prevent="active >= 0 && results[active] ? go(results[active].id) : null"
               :placeholder="$store.ui.lang==='en' ? 'Search people…' : 'Cari pekerja…'" :aria-label="$store.ui.lang==='en' ? 'Search people' : 'Cari pekerja'" autocomplete="off"
               style="width:100%;height:34px;padding:0 12px 0 34px;background:#fff;border:1px solid var(--hairline);border-radius:9px;font-size:var(--t-base);color:var(--ink);outline:none;" />

        <div x-show="open" x-cloak
             style="position:absolute;top:46px;left:0;right:0;background:#fff;border:1px solid var(--hairline);border-radius:10px;box-shadow:var(--shadow-menu);overflow:hidden;z-index:50;max-height:380px;overflow-y:auto;">
            <template x-if="loading">
                <div style="padding:14px 16px;font-size:var(--t-base);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Searching…' : 'Mencari…'">Searching…</div>
            </template>
            <template x-if="!loading && results.length === 0">
                <div style="padding:14px 16px;font-size:var(--t-base);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No matches.' : 'Tiada padanan.'">No matches.</div>
            </template>
            <template x-for="(r, i) in results" :key="r.id">
                <a :href="'{{ url('/app/profile') }}?emp=' + r.id"
                   @mouseenter="active = i"
                   :style="'display:flex;align-items:center;gap:11px;padding:10px 14px;text-decoration:none;border-bottom:1px solid var(--hairline);background:' + (active === i ? 'var(--canvas)' : '#fff')">
                    <span :style="'width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:var(--t-micro);font-weight:600;background:' + (r.avatar_color || '#3a6ea5')" x-text="r.initials"></span>
                    <span style="min-width:0;">
                        <span style="display:block;font-size:var(--t-base);color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="r.name"></span>
                        <span style="display:block;font-size:var(--t-micro);color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="[r.position, r.department].filter(Boolean).join(' · ')"></span>
                    </span>
                </a>
            </template>
        </div>
    </div>
    <div style="flex:1;"></div>

    <div class="uj-seg uj-hd-fold" :title="$store.ui.lang==='en' ? 'Interface language' : 'Bahasa antara muka'">
        <button @click="$store.ui.setLang('en')" :data-on="$store.ui.lang==='en'">EN</button>
        <button @click="$store.ui.setLang('ms')" :data-on="$store.ui.lang==='ms'">BM</button>
    </div>

    <button @click="$dispatch('welcome-open')" class="uj-hd-ib uj-hd-fold" :aria-label="$store.ui.lang==='en' ? 'Show welcome guide' : 'Tunjuk panduan selamat datang'" :title="$store.ui.lang==='en' ? 'Welcome guide' : 'Panduan selamat datang'">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"></path></svg>
    </button>

    @if ($kbEnabled ?? false)
    {{-- Knowledge Bank — opens the slide-over. Amber pulse ring while the user still
         owes this month's lesson; red badge for unread new entries. --}}
    <button @click="kb = true; kbView = 'feed'; $store.kbadge.markRead()"
            class="uj-hd-pill uj-hd-fold"
            :title="$store.ui.lang==='en' ? 'Knowledge Bank' : 'Bank Pengetahuan'">
        @if ($kbOwes ?? false)
            <span aria-hidden="true" class="kb-pulse-ring" style="position:absolute;inset:-3px;border-radius:10px;border:2px solid var(--amber);pointer-events:none;"></span>
        @endif
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z"></path></svg>
        <span class="uj-hd-label" x-text="$store.ui.lang==='en' ? 'Knowledge' : 'Pengetahuan'">Knowledge</span>
        <template x-if="$store.kbadge && $store.kbadge.unread > 0">
            <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:var(--t-micro);display:flex;align-items:center;justify-content:center;" x-text="$store.kbadge.unread"></span>
        </template>
    </button>
    @endif

    @if ($msgEnabled ?? false)
    {{-- Direct messages — opens the slide-over. Red badge for unread messages. --}}
    <button @click="msg = true"
            class="uj-hd-pill uj-hd-fold"
            :title="$store.ui.lang==='en' ? 'Messages' : 'Mesej'">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"></path></svg>
        <span class="uj-hd-label" x-text="$store.ui.lang==='en' ? 'Messages' : 'Mesej'">Messages</span>
        <template x-if="$store.msgbadge && $store.msgbadge.unread > 0">
            <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:var(--t-micro);display:flex;align-items:center;justify-content:center;" x-text="$store.msgbadge.unread"></span>
        </template>
    </button>
    @endif

    @if ($aiEnabled ?? false)
    <button @click="ai = true" class="uj-hd-pill uj-hd-fold" :title="$store.ui.lang==='en' ? 'Ask AI' : 'Tanya AI'" :aria-label="$store.ui.lang==='en' ? 'Ask AI' : 'Tanya AI'">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg>
        <span class="uj-hd-label" x-text="$store.ui.lang==='en' ? 'Ask AI' : 'Tanya AI'">Ask AI</span>
    </button>
    @endif

    {{-- More — priority+ overflow. Holds the secondary actions (Knowledge, Messages,
         Ask AI, help, language) once the header is too narrow to show them inline.
         Shown only at ≤720px header width (see .uj-hd-more in app.css); nothing is
         removed from the header, only relocated here so it stays reachable on phones. --}}
    <div x-data="{ more: false }" class="uj-hd-more" style="position:relative;">
        <button @click="more = ! more" :aria-expanded="more" class="uj-hd-ib"
                :aria-label="$store.ui.lang==='en' ? 'More actions' : 'Lagi tindakan'" :title="$store.ui.lang==='en' ? 'More' : 'Lagi'">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="5" cy="12" r="1.3"></circle><circle cx="12" cy="12" r="1.3"></circle><circle cx="19" cy="12" r="1.3"></circle></svg>
        </button>
        <div x-show="more" x-cloak class="uj-hd-panel" @click.outside="more = false" @keydown.escape.window="more = false"
             style="position:absolute;right:0;top:48px;width:232px;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:var(--shadow-menu);z-index:60;padding:6px;">
            @if ($kbEnabled ?? false)
            <button @click="kb = true; kbView = 'feed'; $store.kbadge.markRead(); more = false" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);background:none;text-align:left;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z"></path></svg>
                <span style="flex:1;" x-text="$store.ui.lang==='en' ? 'Knowledge' : 'Pengetahuan'">Knowledge</span>
                <template x-if="$store.kbadge && $store.kbadge.unread > 0">
                    <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:var(--t-micro);display:flex;align-items:center;justify-content:center;" x-text="$store.kbadge.unread"></span>
                </template>
            </button>
            @endif
            @if ($msgEnabled ?? false)
            <button @click="msg = true; more = false" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);background:none;text-align:left;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"></path></svg>
                <span style="flex:1;" x-text="$store.ui.lang==='en' ? 'Messages' : 'Mesej'">Messages</span>
                <template x-if="$store.msgbadge && $store.msgbadge.unread > 0">
                    <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:var(--t-micro);display:flex;align-items:center;justify-content:center;" x-text="$store.msgbadge.unread"></span>
                </template>
            </button>
            @endif
            @if ($aiEnabled ?? false)
            <button @click="ai = true; more = false" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);background:none;text-align:left;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg>
                <span style="flex:1;" x-text="$store.ui.lang==='en' ? 'Ask AI' : 'Tanya AI'">Ask AI</span>
            </button>
            @endif
            <button @click="$dispatch('welcome-open'); more = false" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);background:none;text-align:left;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"></path></svg>
                <span style="flex:1;" x-text="$store.ui.lang==='en' ? 'Welcome guide' : 'Panduan'">Welcome guide</span>
            </button>
            <div style="height:1px;background:var(--hairline-soft);margin:5px 8px;"></div>
            <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;">
                <span style="flex:1;font-size:var(--t-sm);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Language' : 'Bahasa'">Language</span>
                <div class="uj-seg">
                    <button @click="$store.ui.setLang('en')" :data-on="$store.ui.lang==='en'">EN</button>
                    <button @click="$store.ui.setLang('ms')" :data-on="$store.ui.lang==='ms'">BM</button>
                </div>
            </div>
        </div>
    </div>

    <div x-data="{ notif: false }" style="position:relative;">
        <button @click="notif = ! notif" class="uj-hd-ib" :aria-expanded="notif"
                :aria-label="$store.ui.lang==='en' ? ($store.notifbell.unread ? `Notifications (${$store.notifbell.unread} unread)` : 'Notifications') : ($store.notifbell.unread ? `Pemberitahuan (${$store.notifbell.unread} belum dibaca)` : 'Pemberitahuan')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
            <template x-if="$store.notifbell.unread > 0">
                <span style="position:absolute;top:3px;right:3px;min-width:15px;height:15px;padding:0 3px;background:var(--red);color:#fff;border-radius:9999px;border:1.5px solid #fff;font-size:var(--t-micro);font-weight:700;display:flex;align-items:center;justify-content:center;" x-text="$store.notifbell.unread > 9 ? '9+' : $store.notifbell.unread"></span>
            </template>
        </button>
        <div x-show="notif" x-cloak class="uj-hd-panel" @click.outside="notif = false" @keydown.escape.window="notif = false" style="position:absolute;right:0;top:46px;width:340px;max-width:88vw;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:var(--shadow-menu);z-index:60;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--hairline);">
                <span style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Notifications' : 'Pemberitahuan'">Notifications</span>
                <template x-if="$store.notifbell.unread > 0">
                    <form method="post" action="{{ route('notifications.read') }}">@csrf<button type="submit" style="font-size:var(--t-sm);color:var(--red);background:none;" x-text="$store.ui.lang==='en' ? 'Mark all read' : 'Tanda semua dibaca'">Mark all read</button></form>
                </template>
                {{-- Opt-in must be click-driven: browsers reject a permission request that
                     is not tied to a user gesture. Hidden once granted, denied, or unsupported. --}}
                <button type="button" x-show="$store.alerts.canAsk" x-cloak @click="$store.alerts.enable()"
                        style="font-size:var(--t-sm);color:var(--red);background:none;"
                        x-text="$store.ui.lang==='en' ? 'Turn on alerts' : 'Hidupkan makluman'">Turn on alerts</button>
            </div>
            <div style="max-height:360px;overflow-y:auto;">
                <template x-for="n in $store.notifbell.notifications" :key="n.id">
                    <a :href="n.url || '#'" @click="$store.notifbell.markOne(n.id)" style="display:block;padding:12px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;" :style="{ background: n.read_at ? '#fff' : 'var(--red-tint)' }">
                        <div style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="n.title"></div>
                        <div x-show="n.body" style="font-size:var(--t-sm);color:var(--body);margin-top:2px;line-height:1.45;" x-text="n.body"></div>
                        <div style="font-size:var(--t-micro);color:var(--muted);margin-top:4px;font-family:var(--font-mono);" x-text="n.at"></div>
                    </a>
                </template>
                <template x-if="$store.notifbell.notifications.length === 0">
                    <div style="padding:36px 20px;text-align:center;font-size:var(--t-base);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'You\'re all caught up.' : 'Semua sudah dibaca.'">You're all caught up.</div>
                </template>
            </div>
        </div>
    </div>

    <div style="width:1px;height:26px;background:var(--hairline);"></div>

    {{-- Account menu — avatar opens a dropdown (profile, security, switch workspace, sign out).
         Pinned with flex-shrink:0 so it is always reachable, even on the narrowest phone. --}}
    <div x-data="{ acct: false }" class="uj-hd-acct" style="position:relative;">
        <button @click="acct = ! acct" :aria-label="$store.ui.lang==='en' ? 'Account menu' : 'Menu akaun'" :aria-expanded="acct"
                style="display:flex;align-items:center;gap:9px;background:none;height:40px;padding:0 7px 0 3px;border-radius:10px;transition:background .15s;"
                :style="acct ? { background:'#eae9e3' } : {}">
            <span aria-hidden="true" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:var(--t-sm);font-weight:600;background:{{ auth()->user()->avatarColor() }};">{{ auth()->user()->initials() }}</span>
            <div class="uj-hd-acct-text" style="text-align:left;">
                {{-- Name + Director badge. is_director is an org-status flag (HR-set), separate
                     from the login role — so a director is marked here regardless of persona. --}}
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:var(--t-base);font-weight:600;color:var(--ink);line-height:1.2;white-space:nowrap;">{{ $employee?->display_name ?: auth()->user()->name }}</span>
                    @if ($employee?->is_director)
                        <span style="flex-shrink:0;font-size:var(--t-micro);font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 6px;line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                    @endif
                </div>
                {{-- Real job title from the assigned Position band; falls back to the login-role
                     label only when no band is assigned, so the line is never blank. --}}
                <div style="font-size:var(--t-micro);color:var(--muted);white-space:nowrap;">{{ ($employee?->position) ?: $roleLabel }}</div>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;transition:transform .15s;" :style="acct ? { transform:'rotate(180deg)' } : {}"><path d="M6 9l6 6 6-6"></path></svg>
        </button>

        <div x-show="acct" x-cloak class="uj-hd-panel" @click.outside="acct = false" @keydown.escape.window="acct = false"
             style="position:absolute;right:0;top:50px;width:248px;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:var(--shadow-menu);z-index:60;overflow:hidden;">
            <div style="padding:13px 16px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                    <span style="font-size:var(--t-base);font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $employee?->display_name ?: auth()->user()->name }}</span>
                    @if ($employee?->is_director)
                        <span style="flex-shrink:0;font-size:var(--t-micro);font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 6px;line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                    @endif
                </div>
                @if ($employee?->position)
                    <div style="font-size:var(--t-micro);color:var(--body);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $employee->position }}</div>
                @endif
                <div style="font-size:var(--t-micro);color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ auth()->user()->email }}</div>
            </div>
            <div style="padding:6px;">
                <a href="{{ ($employee ?? null) ? route('app.screen', ['screen' => 'profile', 'emp' => $employee->id]) : route('app.screen', 'profile') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'My profile' : 'Profil saya'">My profile</span>
                </a>
                <a href="{{ route('app.screen', 'security') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Account & security' : 'Akaun & keselamatan'">Account &amp; security</span>
                </a>
                <a href="{{ route('tenant.select') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Switch workspace' : 'Tukar ruang kerja'">Switch workspace</span>
                </a>
            </div>
            <div style="padding:6px;border-top:1px solid var(--hairline-soft);">
                <form action="/logout" method="post">
                    @csrf
                    <button type="submit" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--red);background:none;text-align:left;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"></path></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Sign out' : 'Log keluar'">Sign out</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
