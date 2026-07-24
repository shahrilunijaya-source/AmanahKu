<header class="uj-header" style="height:60px;flex-shrink:0;background:#fff;border-bottom:1px solid var(--hairline);display:flex;align-items:center;gap:16px;padding:0 24px;">
    <button @click="nav = true" class="uj-nav-toggle" :aria-label="$store.ui.lang==='en' ? 'Open navigation' : 'Buka navigasi'" style="width:36px;height:36px;border-radius:8px;align-items:center;justify-content:center;color:var(--body);flex-shrink:0;background:none;">
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
         style="flex:1;max-width:420px;position:relative;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><path d="M21 21l-4-4"></path></svg>
        <input x-model="q" @input="search()" @focus="if (results.length) open = true"
               @keydown.down.prevent="active = Math.min(active + 1, results.length - 1)"
               @keydown.up.prevent="active = Math.max(active - 1, -1)"
               @keydown.enter.prevent="active >= 0 && results[active] ? go(results[active].id) : null"
               :placeholder="$store.ui.lang==='en' ? 'Search people…' : 'Cari pekerja…'" :aria-label="$store.ui.lang==='en' ? 'Search people' : 'Cari pekerja'" autocomplete="off"
               style="width:100%;height:38px;padding:0 14px 0 36px;background:var(--canvas);border:1px solid transparent;border-radius:8px;font-size:13.5px;color:var(--ink);outline:none;" />

        <div x-show="open" x-cloak
             style="position:absolute;top:46px;left:0;right:0;background:#fff;border:1px solid var(--hairline);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,0.12);overflow:hidden;z-index:50;max-height:380px;overflow-y:auto;">
            <template x-if="loading">
                <div style="padding:14px 16px;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Searching…' : 'Mencari…'">Searching…</div>
            </template>
            <template x-if="!loading && results.length === 0">
                <div style="padding:14px 16px;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No matches.' : 'Tiada padanan.'">No matches.</div>
            </template>
            <template x-for="(r, i) in results" :key="r.id">
                <a :href="'{{ url('/app/profile') }}?emp=' + r.id"
                   @mouseenter="active = i"
                   :style="'display:flex;align-items:center;gap:11px;padding:10px 14px;text-decoration:none;border-bottom:1px solid var(--hairline);background:' + (active === i ? 'var(--canvas)' : '#fff')">
                    <span :style="'width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:' + (r.avatar_color || '#3a6ea5')" x-text="r.initials"></span>
                    <span style="min-width:0;">
                        <span style="display:block;font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="r.name"></span>
                        <span style="display:block;font-size:11.5px;color:var(--muted-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="[r.position, r.department].filter(Boolean).join(' · ')"></span>
                    </span>
                </a>
            </template>
        </div>
    </div>
    <div style="flex:1;"></div>

    <div style="display:flex;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;padding:2px;gap:1px;" :title="$store.ui.lang==='en' ? 'Interface language' : 'Bahasa antara muka'">
        <button @click="$store.ui.setLang('en')" :style="'height:30px;padding:0 9px;border-radius:6px;font-size:12px;font-weight:600;background:'+($store.ui.lang==='en'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='en'?'#fff':'var(--muted)')">EN</button>
        <button @click="$store.ui.setLang('ms')" :style="'height:30px;padding:0 9px;border-radius:6px;font-size:12px;font-weight:600;background:'+($store.ui.lang==='ms'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='ms'?'#fff':'var(--muted)')">BM</button>
    </div>

    <button @click="$dispatch('welcome-open')" :aria-label="$store.ui.lang==='en' ? 'Show welcome guide' : 'Tunjuk panduan selamat datang'" :title="$store.ui.lang==='en' ? 'Welcome guide' : 'Panduan selamat datang'" style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--body);background:#fff;border:1px solid var(--hairline);flex-shrink:0;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"></path></svg>
    </button>

    @if ($kbEnabled ?? false)
    {{-- Knowledge Bank — opens the slide-over. Amber pulse ring while the user still
         owes this month's lesson; red badge for unread new entries. --}}
    <button @click="kb = true; kbView = 'feed'; $store.kbadge.markRead()"
            :title="$store.ui.lang==='en' ? 'Knowledge Bank' : 'Bank Pengetahuan'"
            style="position:relative;display:flex;align-items:center;gap:7px;height:36px;padding:0 13px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-weight:500;color:var(--ink);background:#fff;flex-shrink:0;">
        @if ($kbOwes ?? false)
            <span aria-hidden="true" class="kb-pulse-ring" style="position:absolute;inset:-3px;border-radius:10px;border:2px solid var(--amber);pointer-events:none;"></span>
        @endif
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z"></path></svg>
        <span x-text="$store.ui.lang==='en' ? 'Knowledge' : 'Pengetahuan'">Knowledge</span>
        <template x-if="$store.kbadge && $store.kbadge.unread > 0">
            <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:10.5px;display:flex;align-items:center;justify-content:center;" x-text="$store.kbadge.unread"></span>
        </template>
    </button>
    @endif

    @if ($msgEnabled ?? false)
    {{-- Direct messages — opens the slide-over. Red badge for unread messages. --}}
    <button @click="msg = true"
            :title="$store.ui.lang==='en' ? 'Messages' : 'Mesej'"
            style="position:relative;display:flex;align-items:center;gap:7px;height:36px;padding:0 13px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-weight:500;color:var(--ink);background:#fff;flex-shrink:0;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"></path></svg>
        <span x-text="$store.ui.lang==='en' ? 'Messages' : 'Mesej'">Messages</span>
        <template x-if="$store.msgbadge && $store.msgbadge.unread > 0">
            <span style="min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:10.5px;display:flex;align-items:center;justify-content:center;" x-text="$store.msgbadge.unread"></span>
        </template>
    </button>
    @endif

    @if ($aiEnabled ?? false)
    <button @click="ai = true" style="display:flex;align-items:center;gap:7px;height:36px;padding:0 13px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-weight:500;color:var(--ink);background:#fff;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg>
        <span x-text="$store.ui.lang==='en' ? 'Ask AI' : 'Tanya AI'">Ask AI</span>
    </button>
    @endif

    <div x-data="{ notif: false }" style="position:relative;">
        <button @click="notif = ! notif"
                :aria-label="$store.ui.lang==='en' ? @js($unreadCount ? "Notifications ({$unreadCount} unread)" : 'Notifications') : @js($unreadCount ? "Pemberitahuan ({$unreadCount} belum dibaca)" : 'Pemberitahuan')"
                style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;position:relative;background:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
            @if ($unreadCount > 0)
                <span style="position:absolute;top:3px;right:3px;min-width:15px;height:15px;padding:0 3px;background:var(--red);color:#fff;border-radius:9999px;border:1.5px solid #fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
            @endif
        </button>
        <div x-show="notif" x-cloak @click.outside="notif = false" style="position:absolute;right:0;top:46px;width:340px;max-width:88vw;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:0 12px 40px rgba(31,30,26,.14);z-index:60;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--hairline);">
                <span style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Notifications' : 'Pemberitahuan'">Notifications</span>
                @if ($unreadCount > 0)
                    <form method="post" action="{{ route('notifications.read') }}">@csrf<button type="submit" style="font-size:12px;color:var(--red);background:none;" x-text="$store.ui.lang==='en' ? 'Mark all read' : 'Tanda semua dibaca'">Mark all read</button></form>
                @endif
            </div>
            <div style="max-height:360px;overflow-y:auto;">
                @forelse ($notifications as $n)
                    <a href="{{ $n->url ?? '#' }}" style="display:block;padding:12px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;background:{{ $n->read_at ? '#fff' : 'var(--red-tint)' }};">
                        <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $n->title }}</div>
                        @if ($n->body)<div style="font-size:12px;color:var(--body);margin-top:2px;line-height:1.45;">{{ $n->body }}</div>@endif
                        <div style="font-size:11px;color:var(--muted-soft);margin-top:4px;font-family:var(--font-mono);">{{ $n->created_at->diffForHumans() }}</div>
                    </a>
                @empty
                    <div style="padding:36px 20px;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'You\'re all caught up.' : 'Semua sudah dibaca.'">You're all caught up.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div style="width:1px;height:26px;background:var(--hairline);"></div>

    {{-- Account menu — avatar opens a dropdown (profile, security, switch workspace, sign out). --}}
    <div x-data="{ acct: false }" style="position:relative;">
        <button @click="acct = ! acct" :aria-label="$store.ui.lang==='en' ? 'Account menu' : 'Menu akaun'" :aria-expanded="acct"
                style="display:flex;align-items:center;gap:9px;background:none;padding:3px 6px 3px 3px;border-radius:9px;transition:background .15s;"
                :style="acct ? { background:'var(--canvas)' } : {}">
            <span aria-hidden="true" style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;background:{{ auth()->user()->avatarColor() }};">{{ auth()->user()->initials() }}</span>
            <div style="text-align:left;">
                {{-- Name + Director badge. is_director is an org-status flag (HR-set), separate
                     from the login role — so a director is marked here regardless of persona. --}}
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);line-height:1.2;white-space:nowrap;">{{ auth()->user()->name }}</span>
                    @if ($employee?->is_director)
                        <span style="flex-shrink:0;font-size:8.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 6px;line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                    @endif
                </div>
                {{-- Real job title from the assigned Position band; falls back to the login-role
                     label only when no band is assigned, so the line is never blank. --}}
                <div style="font-size:11px;color:var(--muted);white-space:nowrap;">{{ ($employee?->position) ?: $roleLabel }}</div>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;transition:transform .15s;" :style="acct ? { transform:'rotate(180deg)' } : {}"><path d="M6 9l6 6 6-6"></path></svg>
        </button>

        <div x-show="acct" x-cloak @click.outside="acct = false" @keydown.escape.window="acct = false"
             style="position:absolute;right:0;top:50px;width:248px;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:0 12px 40px rgba(31,30,26,.14);z-index:60;overflow:hidden;">
            <div style="padding:13px 16px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:6px;min-width:0;">
                    <span style="font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ auth()->user()->name }}</span>
                    @if ($employee?->is_director)
                        <span style="flex-shrink:0;font-size:8.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 6px;line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                    @endif
                </div>
                @if ($employee?->position)
                    <div style="font-size:11.5px;color:var(--body);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $employee->position }}</div>
                @endif
                <div style="font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ auth()->user()->email }}</div>
            </div>
            <div style="padding:6px;">
                <a href="{{ ($employee ?? null) ? route('app.screen', ['screen' => 'profile', 'emp' => $employee->id]) : route('app.screen', 'profile') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13px;color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'My profile' : 'Profil saya'">My profile</span>
                </a>
                <a href="{{ route('app.screen', 'security') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13px;color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Account & security' : 'Akaun & keselamatan'">Account &amp; security</span>
                </a>
                <a href="{{ route('tenant.select') }}" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13px;color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Switch workspace' : 'Tukar ruang kerja'">Switch workspace</span>
                </a>
            </div>
            <div style="padding:6px;border-top:1px solid var(--hairline-soft);">
                <form action="/logout" method="post">
                    @csrf
                    <button type="submit" class="uj-acct-item" style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13px;color:var(--red);background:none;text-align:left;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"></path></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Sign out' : 'Log keluar'">Sign out</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
