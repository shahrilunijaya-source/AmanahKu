@extends('layouts.app')

@php
    $firstOpen = collect($setupDomains)->firstWhere('complete', false)['key'] ?? ($setupDomains[0]['key'] ?? '');
    $guided = request()->boolean('guided');
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'setup',
    'en'  => [
        'title' => 'Launch Center',
        'body'  => 'Get your workspace ready, grouped by area. Steps tied to data tick automatically as you configure each part; the rest you confirm yourself. Staff stay locked out until the launch-critical steps (marked "Required to launch") are done.',
    ],
    'ms'  => [
        'title' => 'Pusat Pelancaran',
        'body'  => 'Sediakan ruang kerja anda mengikut bidang. Langkah berkaitan data ditanda automatik apabila anda konfigur setiap bahagian; selebihnya anda sahkan sendiri. Staf kekal terkunci sehingga langkah kritikal (bertanda "Diperlukan untuk lancar") selesai.',
    ],
])

@if (session('error'))
    <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13.5px;border-radius:10px;padding:12px 16px;margin-bottom:16px;">{{ session('error') }}</div>
@endif

{{-- Overall progress + launch status --}}
<div class="uj-card" style="padding:24px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div>
            <h3 class="uj-card-title" style="margin-bottom:4px;">
                @if ($setupComplete)
                    <span x-text="$store.ui.lang==='en' ? 'Setup complete' : 'Persediaan selesai'">Setup complete</span>
                @else
                    <span x-text="$store.ui.lang==='en' ? 'Workspace setup' : 'Persediaan ruang kerja'">Workspace setup</span>
                @endif
            </h3>
            <p style="font-size:13px;color:var(--muted);margin:0;">{{ $setupDone }} / {{ $setupTotal }} <span x-text="$store.ui.lang==='en' ? 'steps done' : 'langkah selesai'">steps done</span></p>
        </div>
        <div style="text-align:right;">
            <div style="font-size:30px;font-weight:600;color:{{ $setupComplete ? 'var(--success)' : 'var(--ink)' }};font-family:var(--font-mono);letter-spacing:-1px;">{{ $setupPct }}%</div>
        </div>
    </div>
    <div class="uj-progress" style="margin-top:14px;height:8px;"><span style="width:{{ $setupPct }}%;background:{{ $setupComplete ? 'var(--success)' : 'var(--red)' }};"></span></div>

    {{-- Launch lock status --}}
    <div style="margin-top:16px;display:flex;align-items:flex-start;gap:10px;border-radius:10px;padding:12px 14px;
        {{ $setupCriticalDone ? 'background:#e7f4ee;' : 'background:var(--red-tint);' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="{{ $setupCriticalDone ? 'var(--success)' : 'var(--red)' }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;">
            @if ($setupCriticalDone)<path d="M20 6L9 17l-5-5"></path>@else<circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path>@endif
        </svg>
        <div style="flex:1;font-size:12.8px;color:{{ $setupCriticalDone ? '#176e51' : 'var(--red)' }};">
            @if ($setupCriticalDone)
                <span x-text="$store.ui.lang==='en' ? 'Staff can now sign in — all launch-critical steps are done.' : 'Staf kini boleh log masuk — semua langkah kritikal selesai.'">Staff can now sign in.</span>
            @else
                <div style="font-weight:600;margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Staff are locked out until these are done:' : 'Staf terkunci sehingga ini selesai:'">Staff are locked out until these are done:</div>
                <ul style="margin:0;padding-left:16px;">
                    @foreach ($setupBlocking as $b)
                        <li x-text="$store.ui.lang==='en' ? @js($b['label']) : @js($b['label_ms'])">{{ $b['label'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

{{-- Domain cards (accordion). First incomplete domain opens by default. --}}
<div x-data="{ open: @js($firstOpen) }" style="display:flex;flex-direction:column;gap:12px;">
    @foreach ($setupDomains as $domain)
        <div class="uj-card" style="overflow:hidden;">
            <button type="button" @click="open = (open === @js($domain['key']) ? '' : @js($domain['key']))"
                    style="width:100%;display:flex;align-items:center;gap:14px;padding:16px 20px;cursor:pointer;text-align:left;background:transparent;">
                <span style="width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                    {{ $domain['complete'] ? 'background:#e7f4ee;' : 'background:var(--canvas);' }}">
                    @if ($domain['complete'])
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                    @else
                        <span style="font-size:12px;font-weight:700;color:var(--muted);font-family:var(--font-mono);">{{ $domain['done'] }}/{{ $domain['total'] }}</span>
                    @endif
                </span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($domain['label']) : @js($domain['label_ms'])">{{ $domain['label'] }}</div>
                    <div class="uj-progress" style="margin-top:7px;max-width:200px;"><span style="width:{{ $domain['pct'] }}%;background:{{ $domain['complete'] ? 'var(--success)' : 'var(--red)' }};"></span></div>
                </div>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     :style="open === @js($domain['key']) ? { transform:'rotate(180deg)' } : { transform:'rotate(0deg)' }" style="flex-shrink:0;transition:transform .2s;"><path d="M6 9l6 6 6-6"></path></svg>
            </button>

            <div x-show="open === @js($domain['key'])" x-cloak style="border-top:1px solid var(--hairline-soft);">
                @foreach ($domain['rows'] as $step)
                    @php
                        // Settings-backed steps share one screen; pass ?section= so the inline
                        // frame shows only that step's card. Everything else embeds its own screen.
                        $sectionMap = ['modules' => 'features', 'profile' => 'profile', 'branches' => 'branches', 'departments' => 'departments', 'staff_levels' => 'staff-levels', 'employment_types' => 'employment-types'];
                        $stepQ = array_merge($step['query'] ?? [], ['embed' => 1]);
                        if (isset($sectionMap[$step['key']])) {
                            $stepQ['section'] = $sectionMap[$step['key']];
                        }
                        // The review step IS this wizard — no frame to embed.
                        $embedUrl = $step['screen'] !== 'setup'
                            ? route('app.screen', array_merge(['screen' => $step['screen']], $stepQ))
                            : null;
                    @endphp
                    <div x-data="{ o: false, ld: false }" style="{{ ! $loop->last ? 'border-bottom:1px solid var(--hairline-soft);' : '' }}">
                        <div style="display:flex;align-items:flex-start;gap:14px;padding:15px 20px;">
                            <div style="width:26px;height:26px;border-radius:9999px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;
                                {{ $step['done'] ? 'background:#e7f4ee;color:var(--success);' : 'background:var(--hairline-soft);color:var(--muted);' }}">
                                @if ($step['done'])
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-size:13.5px;font-weight:500;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($step['label']) : @js($step['label_ms'])">{{ $step['label'] }}</span>
                                    @if ($step['critical'])
                                        <span class="uj-pill" style="background:var(--red-tint);color:var(--red);" x-text="$store.ui.lang==='en' ? 'Required to launch' : 'Wajib untuk lancar'">Required to launch</span>
                                    @endif
                                </div>
                                <div style="font-size:12px;color:var(--muted);margin-top:2px;">{{ $step['desc'] }}</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                                @if ($embedUrl)
                                    {{-- Open the real screen (embed mode) in a centered modal — the wizard
                                         stays put, no page growth. Frame lazy-mounts on first open. --}}
                                    <button type="button" @click="o = true; ld = true" class="uj-btn-ghost" style="height:31px;padding:0 13px;font-size:12px;">
                                        <span x-text="$store.ui.lang==='en' ? '{{ $step['done'] ? 'Edit' : 'Set up' }}' : '{{ $step['done'] ? 'Sunting' : 'Sedia' }}'">{{ $step['done'] ? 'Edit' : 'Set up' }}</span>
                                    </button>
                                @endif
                                @unless ($step['auto'])
                                    <form method="post" action="{{ route('setup.step') }}">
                                        @csrf
                                        <input type="hidden" name="step" value="{{ $step['key'] }}">
                                        <button type="submit" class="uj-btn-ghost" style="height:31px;padding:0 13px;font-size:12px;{{ $step['done'] ? 'color:var(--muted);' : 'color:var(--red);' }}">
                                            <span x-text="$store.ui.lang==='en' ? @js($step['done'] ? 'Undo' : 'Mark done') : @js($step['done'] ? 'Buat asal' : 'Tanda siap')">{{ $step['done'] ? 'Undo' : 'Mark done' }}</span>
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </div>
                        @if ($embedUrl)
                            {{-- Centered modal (teleported to body so it escapes the page scroll
                                 container and centres — see modal-centering memory). Fixed height
                                 with the frame scrolling internally, so the wizard never grows. --}}
                            <template x-teleport="body">
                                <div x-show="o" x-cloak @keydown.escape.window="o = false" @click.self="o = false"
                                     style="position:fixed;inset:0;z-index:70;display:flex;padding:24px;background:rgba(20,18,16,.55);overflow-y:auto;">
                                    <div style="width:100%;max-width:1000px;margin:auto;height:84vh;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden;">
                                        <div style="display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                                            <span style="font-size:14.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($step['label']) : @js($step['label_ms'])">{{ $step['label'] }}</span>
                                            @if ($step['critical'])<span class="uj-pill" style="background:var(--red-tint);color:var(--red);" x-text="$store.ui.lang==='en' ? 'Required to launch' : 'Wajib untuk lancar'">Required to launch</span>@endif
                                            <button type="button" @click="o = false" aria-label="Close" style="margin-left:auto;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--muted);background:var(--canvas);font-size:15px;">✕</button>
                                        </div>
                                        <div style="flex:1;min-height:0;background:var(--canvas);">
                                            <template x-if="ld">
                                                <iframe src="{{ $embedUrl }}" data-embed style="width:100%;height:100%;border:0;display:block;"></iframe>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>

@unless ($setupComplete)
    <div style="margin-top:16px;display:flex;justify-content:flex-end;">
        <form method="post" action="{{ route('setup.finish') }}">
            @csrf
            <button type="submit" class="uj-btn-primary" @disabled(! $setupAllDone)
                style="height:44px;padding:0 24px;font-size:14px;{{ ! $setupAllDone ? 'opacity:.5;cursor:not-allowed;' : '' }}">
                <span x-text="$store.ui.lang==='en' ? 'Finish setup' : 'Selesaikan persediaan'">Finish setup</span>
            </button>
        </form>
    </div>
@endunless
@endsection
