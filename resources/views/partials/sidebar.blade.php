<aside class="uj-sidebar" :class="nav ? 'uj-sidebar-open' : ''" style="width:248px;flex-shrink:0;background:var(--sidebar);display:flex;flex-direction:column;height:100vh;">
    <div style="height:60px;display:flex;align-items:center;gap:10px;padding:0 18px;border-bottom:1px solid var(--sidebar-line);flex-shrink:0;">
        <div style="width:28px;height:28px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;">A</div>
        <span style="font-weight:600;font-size:16px;color:#fff;letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
    </div>
    <div style="padding:0 18px;">@include('partials.env-badge')</div>

    {{-- ── QUICK ACTIONS — persistent white dock above the nav: the 3 daily-driver flows
         (clock · task · timesheet) reachable from every screen. Shown only when the
         signed-in user has an employee record (qaShow). ── --}}
    @if (($qaShow ?? false))
        @php
            $qci = $qaCi ? \Illuminate\Support\Str::of($qaCi)->limit(5, '') : null;
            $qco = $qaCo ? \Illuminate\Support\Str::of($qaCo)->limit(5, '') : null;
            $qPct = rtrim(rtrim(number_format($qaTsPct ?? 0, 1), '0'), '.');
        @endphp
        <style>
            .qa-row{display:flex;align-items:center;gap:10px;width:100%;min-height:40px;padding:5px 8px;border-radius:10px;text-decoration:none;transition:background .14s ease;}
            .qa-row:hover{background:var(--canvas);}
            .qa-ico{width:28px;height:28px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
            .qa-chev{color:var(--muted-soft);font-size:16px;line-height:1;flex-shrink:0;}
        </style>
        <div style="margin:14px 10px 6px;background:#fff;border-radius:14px;padding:13px 11px 9px;box-shadow:0 6px 18px rgba(0,0,0,.15);">
            <div style="font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--muted-soft);margin:0 3px 9px;" x-text="$store.ui.lang==='en' ? 'Quick actions' : 'Tindakan pantas'">Quick actions</div>

            {{-- Attendance — redirect to the full Attendance screen (clock in/out, history, selfie). --}}
            <a href="{{ route('app.screen', 'attendance') }}" class="qa-row">
                <span class="qa-ico" style="background:{{ $qci && ! $qco ? '#e7f4ee' : ($qco ? 'var(--canvas)' : 'var(--red-tint)') }};">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="{{ $qci && ! $qco ? 'var(--success)' : ($qco ? 'var(--muted)' : 'var(--red)') }}" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                </span>
                <span style="flex:1;font-size:12.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Attendance' : 'Kehadiran'">Attendance</span>
                @if ($qco)
                    <span style="font-size:10px;font-weight:700;font-family:var(--font-mono);padding:2px 6px;border-radius:6px;color:var(--muted);background:var(--canvas);" x-text="$store.ui.lang==='en' ? 'Done' : 'Selesai'">Done</span>
                @elseif ($qci)
                    <span style="font-size:10px;font-weight:700;font-family:var(--font-mono);padding:2px 6px;border-radius:6px;color:var(--success);background:#e7f4ee;">{{ $qci }}</span>
                @else
                    <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:6px;color:var(--red);background:var(--red-tint);" x-text="$store.ui.lang==='en' ? 'Clock in' : 'Clock-in'">Clock in</span>
                @endif
                <span class="qa-chev">›</span>
            </a>

            <div style="height:1px;background:var(--hairline-soft);margin:9px 3px 6px;"></div>

            {{-- Board — redirect to the full Tasks, Assignments & Adhoc board. --}}
            <a href="{{ route('app.screen', 'board') }}" class="qa-row">
                <span class="qa-ico" style="background:#fbf3e6;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M9 3v18M15 3v18"></path></svg>
                </span>
                <span style="flex:1;font-size:12.5px;font-weight:600;color:var(--ink);line-height:1.2;" x-text="'T.A.A.'">T.A.A.</span>
                <span class="qa-chev">›</span>
            </a>

            {{-- Timesheet — today's allocation at a glance + jump to the grid. --}}
            @if ($qaTsEnabled)
                <a href="{{ route('app.screen', 'timesheets') }}" class="qa-row">
                    <span class="qa-ico" style="background:#eaf1f8;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--info)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><rect x="7" y="11" width="3" height="6"></rect><rect x="13" y="7" width="3" height="10"></rect></svg>
                    </span>
                    <span style="flex:1;font-size:12.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Timesheet' : 'Lembaran masa'">Timesheet</span>
                    <span style="font-size:10px;font-weight:700;font-family:var(--font-mono);padding:2px 6px;border-radius:6px;color:{{ abs($qaTsPct - 100) < 0.01 ? 'var(--success)' : 'var(--muted)' }};background:{{ abs($qaTsPct - 100) < 0.01 ? '#e7f4ee' : 'var(--canvas)' }};">{{ $qPct }}%</span>
                    <span class="qa-chev">›</span>
                </a>
            @endif
        </div>
    @endif

    <nav style="flex:1;overflow-y:auto;padding:10px 10px;">
        {{-- Group the flat nav into labelled, collapsible sections. groupBy keeps
             first-seen order, and Amanahku::nav() emits items contiguously per
             section, so section order is preserved. A section auto-expands when it
             contains the active screen — keeping the visible list short otherwise. --}}
        @foreach (collect($nav)->groupBy('section') as $section => $items)
            @php
                $sectionMs = $items->first()['section_ms'] ?? $section;
                $sectionActive = $items->contains(fn ($i) => ($i['active'] ?? false) || ($i['expanded'] ?? false));
            @endphp
            <div x-data="{ sec: {{ $sectionActive ? 'true' : 'false' }} }" style="margin-bottom:2px;">
                <button @click="sec = !sec" type="button"
                        style="width:100%;display:flex;align-items:center;gap:8px;padding:9px 10px 5px;background:none;border:none;cursor:pointer;">
                    <span style="flex:1;text-align:left;font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:#6b6860;"
                          x-text="$store.ui.lang==='en' ? @js($section) : @js($sectionMs)">{{ $section }}</span>
                    <span style="font-size:9px;color:#6b6860;" x-text="sec ? '▾' : '▸'"></span>
                </button>
                <div x-show="sec" x-cloak style="margin-bottom:6px;">
                    @foreach ($items as $item)
                        <div x-data="{ open: {{ $item['expanded'] ? 'true' : 'false' }} }" style="margin-bottom:2px;">
                            @if ($item['hasChildren'])
                                <button @click="open = !open" class="{{ $item['active'] ? '' : 'uj-side-link' }}"
                                        style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13.5px;font-weight:500;color:{{ $item['active'] ? '#fff' : 'var(--sidebar-text)' }};background:{{ $item['active'] ? 'var(--red)' : 'transparent' }};">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="{{ $item['icon'] }}"></path></svg>
                                    <span style="flex:1;text-align:left;" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                    <span style="font-size:10px;color:#6b6860;" x-text="open ? '▾' : '▸'"></span>
                                </button>
                                <div x-show="open" x-cloak style="margin:2px 0 6px 0;">
                                    @foreach ($item['children'] as $child)
                                        <a href="{{ route('app.screen', array_merge(['screen' => $child['id']], $child['query'] ?? [])) }}" class="{{ $child['active'] ? '' : 'uj-side-link' }}"
                                           style="display:flex;align-items:center;gap:11px;padding:7px 10px 7px 38px;border-radius:8px;font-size:13px;font-weight:500;text-align:left;text-decoration:none;color:{{ $child['active'] ? '#fff' : '#9a978e' }};background:{{ $child['active'] ? 'var(--sidebar-soft)' : 'transparent' }};" x-text="$store.ui.lang==='en' ? @js($child['label']) : @js($child['label_ms'] ?? $child['label'])">{{ $child['label'] }}</a>
                                    @endforeach
                                </div>
                            @else
                                <a href="{{ route('app.screen', ['screen' => $item['id']]) }}" class="{{ $item['active'] ? '' : 'uj-side-link' }}"
                                   style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:13.5px;font-weight:500;text-decoration:none;color:{{ $item['active'] ? '#fff' : 'var(--sidebar-text)' }};background:{{ $item['active'] ? 'var(--red)' : 'transparent' }};">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="{{ $item['icon'] }}"></path></svg>
                                    <span style="flex:1;text-align:left;" x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    {{-- Feedback — pinned above the workspace switcher. Opens the global feedback modal. --}}
    <div style="padding:12px 14px 0;flex-shrink:0;">
        <button type="button" @click="$dispatch('feedback-open')" class="uj-feedback-btn"
                style="width:100%;display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:13px;font-weight:500;text-align:left;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--red);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span style="flex:1;" x-text="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'">Send feedback</span>
            <span x-show="$store.changelog.unseen" x-cloak style="font-size:9px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#fff;background:var(--red);border-radius:9999px;padding:2px 6px;">New</span>
        </button>
    </div>

    <div style="padding:12px 14px;border-top:1px solid var(--sidebar-line);flex-shrink:0;margin-top:10px;">
        <a href="{{ route('tenant.select') }}" style="width:100%;display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;text-decoration:none;">
            <div style="width:30px;height:30px;border-radius:8px;background:{{ $tenant['color'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">{{ $tenant['initials'] }}</div>
            <div style="flex:1;min-width:0;text-align:left;">
                <div style="font-size:12.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $tenant['name'] }}</div>
                <div style="font-size:11px;color:#807d72;">{{ $tenant['plan'] }} · <span x-text="$store.ui.lang==='en' ? 'switch' : 'tukar'">switch</span></div>
            </div>
            <span style="color:#807d72;font-size:12px;">⇄</span>
        </a>
    </div>
</aside>
