{{--
    Screen guide banner — teaches a newbie HR user what a screen is for.
    Bilingual (English + Bahasa Malaysia) with an EN|BM toggle wired to the
    global $store.ui.lang, so switching language updates every banner + hint at
    once. Collapsed by default: only a small "What is this screen for?" strip
    shows; clicking it opens the full guide in a centered modal (teleported to
    <body> so it escapes the page scroll container — see board.blade.php).

    Usage (bilingual — preferred):
        @include('partials.guide', [
            'key' => 'overtime',
            'en'  => ['title' => '...', 'body' => '...', 'who' => '...', 'steps' => ['...']],
            'ms'  => ['title' => '...', 'body' => '...', 'who' => '...', 'steps' => ['...']],
        ])

    Usage (flat — legacy, English only; BM falls back to English):
        @include('partials.guide', ['key' => 'x', 'title' => '...', 'body' => '...', 'who' => '...', 'steps' => [...]])

    Required: $key, plus either ($en) or flat ($title,$body). $who/$steps optional.
--}}
{{-- In embed mode (screen inlined in the Setup wizard) the guide is redundant — the
     wizard already frames the context — so skip the banner entirely. --}}
@if (request()->boolean('embed'))
    @php return; @endphp
@endif
@php
    $en = $en ?? ['title' => $title ?? '', 'body' => $body ?? '', 'who' => $who ?? null, 'steps' => $steps ?? []];
    $ms = $ms ?? $en;
    foreach (['en' => &$en, 'ms' => &$ms] as $bag) {
        $bag['who'] = $bag['who'] ?? null;
        $bag['steps'] = $bag['steps'] ?? [];
    }
    unset($bag);
@endphp
<div x-data="{
        open: false,
        copy: {{ \Illuminate\Support\Js::from(['en' => $en, 'ms' => $ms]) }},
        get c() { return this.copy[$store.ui.lang] ?? this.copy.en; }
     }"
     style="margin-bottom:16px;">

    {{-- Collapsed strip — the only thing shown by default --}}
    <button @click="open = true"
            style="display:flex;align-items:center;gap:8px;background:none;font-size:12.5px;color:var(--muted);padding:2px 0;cursor:pointer;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
        <span x-text="$store.ui.lang==='en' ? 'What is this screen for?' : 'Apa fungsi skrin ini?'"></span>
    </button>

    {{-- Guide modal — teleported to <body> so position:fixed anchors to the viewport --}}
    <template x-teleport="body">
    <div x-show="open" x-cloak @click.self="open = false" @keydown.escape.window="open = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <div class="uj-card" style="width:100%;max-width:600px;margin:auto;padding:0;overflow:hidden;border-top:3px solid var(--red);">

            {{-- header --}}
            <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--hairline);">
                <div style="width:34px;height:34px;border-radius:9px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"></path></svg>
                </div>
                <div style="flex:1;min-width:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin:0;" x-text="c.title"></h3>
                    <template x-if="c.who">
                        <span class="uj-pill" style="background:var(--canvas);color:var(--muted);font-size:11px;" x-text="c.who"></span>
                    </template>
                </div>
                <button type="button" @click="open = false" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;flex-shrink:0;">&times;</button>
            </div>

            {{-- body --}}
            <div style="padding:18px 20px;">
                <p style="font-size:13.5px;color:var(--body);margin:0;line-height:1.6;" x-text="c.body"></p>

                <template x-if="c.steps && c.steps.length">
                    <ol style="margin:14px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px;">
                        <template x-for="(step, i) in c.steps" :key="i">
                            <li style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--body);line-height:1.5;">
                                <span style="width:19px;height:19px;border-radius:50%;background:var(--red);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;" x-text="i + 1"></span>
                                <span x-text="step"></span>
                            </li>
                        </template>
                    </ol>
                </template>
            </div>

            {{-- footer: language toggle + close --}}
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;border-top:1px solid var(--hairline);">
                <div style="display:flex;background:var(--canvas);border:1px solid var(--hairline);border-radius:7px;padding:2px;gap:1px;">
                    <button type="button" @click="$store.ui.setLang('en')" :style="'padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;background:'+($store.ui.lang==='en'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='en'?'#fff':'var(--muted)')">EN</button>
                    <button type="button" @click="$store.ui.setLang('ms')" :style="'padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;background:'+($store.ui.lang==='ms'?'var(--red)':'transparent')+';color:'+($store.ui.lang==='ms'?'#fff':'var(--muted)')">BM</button>
                </div>
                <button type="button" @click="open = false" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;"
                        x-text="$store.ui.lang==='en' ? 'Got it' : 'Faham'"></button>
            </div>
        </div>
    </div>
    </template>
</div>
