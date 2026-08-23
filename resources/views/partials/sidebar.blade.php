<aside class="uj-sidebar">
    <div class="uj-sb-brand">
        <a href="{{ route('app.screen', 'dash') }}" style="display:flex;align-items:center;gap:10px;text-decoration:none;min-width:0;">
            <div style="width:26px;height:26px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:var(--t-base);flex-shrink:0;">A</div>
            <span class="uj-sb-hide" style="font-weight:600;font-size:var(--t-lg);color:#fff;letter-spacing:-0.2px;white-space:nowrap;">Amanah<span style="color:var(--red);">ku</span></span>
        </a>
        <div class="uj-sb-hide" style="flex:1;"></div>
        {{-- Collapse to the rail (desktop). Same action as the topbar menu button and Ctrl+B. --}}
        <button type="button" @click="toggleSb()" class="uj-sb-tgl uj-sb-hide"
                :aria-label="$store.ui.lang==='en' ? 'Collapse sidebar' : 'Kecilkan bar sisi'"
                :title="$store.ui.lang==='en' ? 'Collapse sidebar (Ctrl+B)' : 'Kecilkan bar sisi (Ctrl+B)'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M9 4v16"></path></svg>
        </button>
    </div>
    <div class="uj-sb-hide" style="padding:0 14px 8px;">@include('partials.env-badge', ['onDark' => true])</div>

    {{-- ── TODAY — reports the clock state and nothing else. The three screens this
         dock used to be the only route to (attendance · T.A.A. · timesheet) are
         ordinary nav rows under My Work now, so collapsing the sidebar no longer
         hides them. Shown only when the signed-in user has an employee record. ── --}}
    @if (($qaShow ?? false))
        @php
            $qci = $qaCi ? \Illuminate\Support\Str::of($qaCi)->limit(5, '') : null;
            $qco = $qaCo ? \Illuminate\Support\Str::of($qaCo)->limit(5, '') : null;
            $qPct = rtrim(rtrim(number_format($qaTsPct ?? 0, 1), '0'), '.');
            $qFull = abs(($qaTsPct ?? 0) - 100) < 0.01;
        @endphp
        <div class="uj-sb-today">
            <div class="uj-sb-hide" style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;">
                <span class="uj-sb-eyebrow" x-text="$store.ui.lang==='en' ? 'Today' : 'Hari ini'">Today</span>
                <span style="font-family:var(--font-mono);font-size:var(--t-micro);color:var(--muted-soft);white-space:nowrap;">{{ now()->format('D j M') }}</span>
            </div>

            <div class="uj-sb-hide" style="display:flex;align-items:baseline;gap:9px;">
                <span class="uj-sb-clock">{{ $qci ?: '--:--' }}</span>
                <span style="font-size:var(--t-micro);color:var(--sidebar-dim);">
                    @if ($qco)
                        <span x-text="$store.ui.lang==='en' ? 'clocked out' : 'sudah keluar'">clocked out</span>
                    @elseif ($qci)
                        <span x-text="$store.ui.lang==='en' ? 'clocked in' : 'sudah masuk'">clocked in</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'not clocked in' : 'belum masuk'">not clocked in</span>
                    @endif
                </span>
            </div>

            <div class="uj-sb-hide" style="display:flex;gap:6px;">
                <a href="{{ route('app.screen', 'attendance') }}" class="uj-sb-ghost" style="text-decoration:none;">
                    @if ($qco)
                        <span x-text="$store.ui.lang==='en' ? 'Attendance' : 'Kehadiran'">Attendance</span>
                    @elseif ($qci)
                        <span x-text="$store.ui.lang==='en' ? 'Clock out' : 'Clock-out'">Clock out</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'Clock in' : 'Clock-in'">Clock in</span>
                    @endif
                </a>
            </div>

            @if ($qaTsEnabled)
                <div class="uj-sb-hide">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:5px;">
                        <span style="font-size:var(--t-micro);color:var(--sidebar-dim);" x-text="$store.ui.lang==='en' ? 'Timesheet' : 'Lembaran masa'">Timesheet</span>
                        <span style="font-family:var(--font-mono);font-size:var(--t-micro);font-weight:600;color:{{ $qFull ? 'var(--success)' : 'var(--amber)' }};">{{ $qPct }}%</span>
                    </div>
                    <div class="uj-tsbar"><span style="width:{{ min(100, max(0, $qaTsPct ?? 0)) }}%;background:{{ $qFull ? 'var(--success)' : 'var(--amber)' }};"></span></div>
                </div>
            @endif

            {{-- Rail: the clock only. Everything else in the dock is a nav row. --}}
            <div class="uj-rail-only" style="flex-direction:column;align-items:center;gap:3px;padding:3px 0;">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="{{ $qci && ! $qco ? 'var(--success)' : ($qco ? 'var(--sidebar-dim)' : 'var(--red)') }}" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                <span style="font-family:var(--font-mono);font-size:var(--t-micro);font-weight:600;color:#fff;">{{ $qci ?: '--:--' }}</span>
            </div>
        </div>
    @endif

    {{-- First visit only. Explains the section rows, then never returns.
         Sat outside <nav> on purpose: that column scrolls, so it clips anything wider
         than 248px and the bubble is 340px. $side puts it to the right of the column
         rather than under a row, where it would cover the five rows it is telling
         people to hover — and to the right is where the section panel opens anyway.

         Dashboard only. The sidebar renders on every screen, and some screens carry
         their own coachmark — Attendance has two — so shown everywhere this one lands
         on top of them. Dashboard is where you arrive after signing in and it has no
         bubble of its own, so that is where this one waits. --}}
    @if ((request()->route('screen') ?? 'dash') === 'dash')
    <div>
        @include('partials.coachmark', [
            'key' => 'sidebar-sections',
            'side' => true,
            'en'  => [
                'title' => 'New: the sidebar lists sections',
                'body'  => 'Each row is a section, so point at one and its screens open in a panel right here.',
            ],
            'ms'  => [
                'title' => 'Baharu: bar sisi menyenaraikan bahagian',
                'body'  => 'Setiap baris ialah satu bahagian, jadi halakan tetikus pada satu dan skrinnya terbuka dalam panel di sini.',
            ],
        ])
    </div>
    @endif

    <nav class="uj-sb-nav">
        {{-- ── One row per SECTION, nothing nested on show. ──────────────────────
             The tree used to sit here in full: every section header, every screen,
             every child. It ran to eighteen-odd rows and it made the sidebar the
             tallest thing on the page. Now the sidebar is a column of sections and
             the screens inside one live in a panel that opens beside it on hover
             (or on click, which is the keyboard/screen-reader route in). Ctrl+B
             still swaps the 248px column for the 64px rail, but it no longer
             changes what is nested where.

             This sidebar is desktop-only — below 900px it does not render and the
             bottom dock takes over — so there is no second, hover-less copy of the
             nav to keep in step any more.

             A section holding a single leaf (Overview → Dashboard) skips the panel
             and links straight through: a hover panel to reveal one row is a step
             that buys nothing. --}}
        <div>
            @foreach (collect($nav)->groupBy('section') as $section => $items)
                @php
                    $sectionMs = $items->first()['section_ms'] ?? $section;
                    $secIcon = \App\Support\Amanahku::sectionIcon($section);
                    // The section lights up when the current screen is anywhere inside it,
                    // parent or child — that is the only "you are here" left once the tree
                    // stops rendering its rows.
                    $secOn = $items->contains(fn ($i) => $i['active'] || collect($i['children'] ?? [])->contains(fn ($c) => $c['active'] ?? false));
                    $solo = $items->count() === 1 && ! $items->first()['hasChildren'];
                @endphp
                @if ($solo)
                    @php $only = $items->first(); @endphp
                    <a href="{{ route('app.screen', ['screen' => $only['id']]) }}" class="uj-nav-row"
                       @if ($secOn) data-on @endif
                       :title="sbCollapsed ? ($store.ui.lang==='en' ? @js($only['label']) : @js($only['label_ms'] ?? $only['label'])) : null">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $secIcon }}"></path></svg>
                        <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? @js($only['label']) : @js($only['label_ms'] ?? $only['label'])">{{ $only['label'] }}</span>
                    </a>
                @else
                    <div x-data="sbSec" @mouseenter="show($event)" @mouseleave="hide()"
                         @keydown.escape="close()" @click.outside="close()" style="position:relative;">
                        <button type="button" class="uj-nav-row" @click="toggle($event)"
                                :aria-expanded="fly ? 'true' : 'false'" @if ($secOn) data-on @endif>
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $secIcon }}"></path></svg>
                            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? @js($section) : @js($sectionMs)">{{ $section }}</span>
                            <svg class="uj-nav-chev uj-sb-hide" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"></path></svg>
                        </button>

                        {{-- The panel. position:fixed because the nav scrolls, so an absolute
                             one would be clipped by it; mounted only while open. --}}
                        <template x-if="fly">
                            <div class="uj-fly uj-fly-grid" x-ref="fly" data-open>
                                <div class="uj-fly-h" x-text="$store.ui.lang==='en' ? @js($section) : @js($sectionMs)">{{ $section }}</div>
                                {{-- A group (Oversight, Offboarding) keeps ONE cell here and opens
                                     its own panel to the right on hover — its screens are not loose
                                     entries in the section grid. Two hovers deep is the whole depth
                                     of the nav; nothing nests below this. --}}
                                <div class="uj-fly-cols">
                                    @foreach ($items as $item)
                                        @if ($item['hasChildren'])
                                            <div x-data="sbSub" @mouseenter="openSub($event)" @mouseleave="closeSub()" style="position:relative;">
                                                @php
                                                    $groupOn = $item['active'] || collect($item['children'])->contains(fn ($c) => $c['active'] ?? false);
                                                @endphp
                                                @if ($item['landing'] ?? false)
                                                    <a href="{{ route('app.screen', ['screen' => $item['id']]) }}" class="uj-fly-lnk uj-fly-grp"
                                                       @if ($groupOn) data-on @endif>
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                                        <span x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                                        <svg class="uj-fly-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"></path></svg>
                                                    </a>
                                                @else
                                                    {{-- No page of its own, so the cell is only a door to the
                                                         sub-panel: a button, and click opens it for the keyboard. --}}
                                                    <button type="button" class="uj-fly-lnk uj-fly-grp" @click="openSub($event)"
                                                            :aria-expanded="sub ? 'true' : 'false'" @if ($groupOn) data-on @endif>
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                                        <span x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                                        <svg class="uj-fly-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"></path></svg>
                                                    </button>
                                                @endif

                                                <template x-if="sub">
                                                    <div class="uj-fly uj-fly-sub" x-ref="sub" data-open>
                                                        <div class="uj-fly-h" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</div>
                                                        @foreach ($item['children'] as $child)
                                                            <a href="{{ route('app.screen', array_merge(['screen' => $child['id']], $child['query'] ?? [])) }}"
                                                               class="uj-fly-lnk" @if ($child['active']) data-on @endif>
                                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                                                <span x-text="$store.ui.lang==='en' ? @js($child['label']) : @js($child['label_ms'] ?? $child['label'])">{{ $child['label'] }}</span>
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                </template>
                                            </div>
                                        @else
                                            <a href="{{ route('app.screen', ['screen' => $item['id']]) }}" class="uj-fly-lnk"
                                               @if ($item['active']) data-on @endif>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                                <span x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            @endforeach
        </div>
    </nav>

    <div class="uj-sb-foot">
        {{-- Raise a ticket — opens the shared ticket-raise modal pre-filled to category Bug.
             Hidden when module.helpdesk is off: the modal it opens doesn't render and its
             submit route 404s, so the button would otherwise dangle. Same $helpdeskEnabled
             the ticket-raise partial itself checks — both come from the shared composer. --}}
        @if ($helpdeskEnabled ?? true)
        <button type="button" @click="$dispatch('ticket-raise-open', { category: 'Bug' })" class="uj-feedback-btn"
                :title="$store.ui.lang==='en' ? 'Raise a ticket' : 'Buka ticket'"
                style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--red);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Raise a ticket' : 'Buka ticket'">Raise a ticket</span>
        </button>
        @endif

        <a href="{{ route('app.screen', 'changelog') }}" class="uj-feedback-btn"
           :title="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'"
           style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;text-decoration:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--sidebar-dim);"><path d="M3 6h.01M3 12h.01M3 18h.01M8 6h13M8 12h13M8 18h13"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'">Changelog</span>
        </a>

        <a href="{{ route('tenant.select') }}" class="uj-sb-ws"
           :title="$store.ui.lang==='en' ? 'Switch workspace' : 'Tukar ruang kerja'">
            <div style="width:28px;height:28px;border-radius:8px;background:{{ $tenant['color'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:var(--t-sm);flex-shrink:0;">{{ $tenant['initials'] }}</div>
            <div class="uj-sb-hide" style="flex:1;min-width:0;text-align:left;">
                <div style="font-size:var(--t-sm);font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $tenant['name'] }}</div>
                <div style="font-size:var(--t-micro);color:var(--muted-soft);">{{ $tenant['plan'] }} · <span x-text="$store.ui.lang==='en' ? 'switch' : 'tukar'">switch</span></div>
            </div>
            <svg class="uj-sb-hide" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--sidebar-dim)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
        </a>
    </div>
</aside>
