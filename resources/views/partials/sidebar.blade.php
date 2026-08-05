<aside class="uj-sidebar" :class="nav ? 'uj-sidebar-open' : ''">
    <div class="uj-sb-brand">
        <div style="width:26px;height:26px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:var(--t-base);flex-shrink:0;">A</div>
        <span class="uj-sb-hide" style="font-weight:600;font-size:var(--t-lg);color:#fff;letter-spacing:-0.2px;white-space:nowrap;">Amanah<span style="color:var(--red);">ku</span></span>
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

    <nav class="uj-sb-nav">
        {{-- Group the flat nav into labelled, collapsible sections. groupBy keeps
             first-seen order, and Amanahku::nav() emits items contiguously per
             section, so section order is preserved. Sections start OPEN: the tree is
             about eighteen rows now, so hiding all but the active section cost more
             in orientation than it saved in height. The headings still collapse. --}}
        @foreach (collect($nav)->groupBy('section') as $section => $items)
            @php $sectionMs = $items->first()['section_ms'] ?? $section; @endphp
            <div x-data="{ sec: true }" class="uj-nav-grp">
                <button @click="sec = !sec" type="button" class="uj-nav-sec"
                        x-text="$store.ui.lang==='en' ? @js($section) : @js($sectionMs)">{{ $section }}</button>
                <div class="uj-nav-rule"></div>
                {{-- In rail mode there are no section headers to collapse, so the rows
                     always show; `sec` only governs the expanded sidebar. --}}
                <div x-show="sec || sbCollapsed" x-cloak>
                    @foreach ($items as $item)
                        <div x-data="sbFly({{ $item['expanded'] ? 'true' : 'false' }})"
                             @mouseenter="show($event)" @mouseleave="hide()" style="position:relative;">
                            @if ($item['hasChildren'])
                                <button type="button" @click="open = !open" class="uj-nav-row" :aria-expanded="open"
                                        @if ($item['active']) data-on @endif>
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                    <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                    <svg class="uj-nav-chev uj-sb-hide" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"></path></svg>
                                </button>
                                <div class="uj-nav-kids" x-show="open" x-cloak>
                                    @foreach ($item['children'] as $child)
                                        <a href="{{ route('app.screen', array_merge(['screen' => $child['id']], $child['query'] ?? [])) }}"
                                           class="uj-nav-kid" @if ($child['active']) data-on @endif
                                           x-text="$store.ui.lang==='en' ? @js($child['label']) : @js($child['label_ms'] ?? $child['label'])">{{ $child['label'] }}</a>
                                    @endforeach
                                </div>
                            @else
                                <a href="{{ route('app.screen', ['screen' => $item['id']]) }}"
                                   class="uj-nav-row" @if ($item['active']) data-on @endif>
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                    <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                </a>
                            @endif

                            {{-- Rail flyout: the same row again, opened beside the icon. Only
                                 mounted while hovering, and only while the rail is collapsed. --}}
                            <template x-if="fly">
                                <div class="uj-fly" x-ref="fly" data-open>
                                    <div class="uj-fly-t">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                                        <span style="flex:1;" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                    </div>
                                    @if ($item['hasChildren'])
                                        <div class="uj-nav-kids">
                                            @foreach ($item['children'] as $child)
                                                <a href="{{ route('app.screen', array_merge(['screen' => $child['id']], $child['query'] ?? [])) }}"
                                                   class="uj-nav-kid" @if ($child['active']) data-on @endif
                                                   x-text="$store.ui.lang==='en' ? @js($child['label']) : @js($child['label_ms'] ?? $child['label'])">{{ $child['label'] }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="uj-sb-foot">
        {{-- Send feedback — opens the shared ticket-raise modal pre-filled to category Bug. --}}
        <button type="button" @click="$dispatch('ticket-raise-open', { category: 'Bug' })" class="uj-feedback-btn"
                :title="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'"
                style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--red);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'">Send feedback</span>
            <span x-show="$store.changelog.unseen" x-cloak class="uj-sb-hide" style="font-size:var(--t-micro);font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#fff;background:var(--red);border-radius:9999px;padding:1px 7px;">New</span>
        </button>

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
