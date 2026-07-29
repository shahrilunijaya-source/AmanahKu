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
     x-init="$watch('open', value => {
         if (value) {
             $nextTick(() => $refs.closeBtn?.focus());
         } else {
             $nextTick(() => $refs.trigger?.focus());
         }
     })"
     class="uj-guide-host">

    {{-- Collapsed strip — the only thing shown by default --}}
    <button type="button"
            x-ref="trigger"
            @click="open = true"
            class="uj-guide-trigger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
        <span x-text="$store.ui.lang==='en' ? 'What is this screen for?' : 'Apa fungsi skrin ini?'"></span>
    </button>

    {{-- Guide modal — teleported to <body> so position:fixed anchors to the viewport --}}
    <template x-teleport="body">
    <div x-show="open"
         x-cloak
         @click.self="open = false"
         @keydown.escape.window="open = false"
         role="dialog"
         aria-modal="true"
         aria-labelledby="uj-guide-title"
         class="uj-guide-overlay"
         x-transition:enter="uj-guide-fade-enter"
         x-transition:enter-start="uj-guide-fade-start"
         x-transition:enter-end="uj-guide-fade-end"
         x-transition:leave="uj-guide-fade-leave"
         x-transition:leave-start="uj-guide-fade-end"
         x-transition:leave-end="uj-guide-fade-start">
        <div class="uj-card uj-guide-card"
             x-transition:enter="uj-guide-card-enter"
             x-transition:enter-start="uj-guide-card-start"
             x-transition:enter-end="uj-guide-card-end"
             x-transition:leave="uj-guide-card-leave"
             x-transition:leave-start="uj-guide-card-end"
             x-transition:leave-end="uj-guide-card-start">

            {{-- header --}}
            <div class="uj-guide-head">
                <div class="uj-guide-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"></path></svg>
                </div>
                <div class="uj-guide-title-wrap">
                    <h3 id="uj-guide-title" class="uj-guide-title" x-text="c.title"></h3>
                    <template x-if="c.who">
                        <span class="uj-pill uj-guide-who" x-text="c.who"></span>
                    </template>
                </div>
                <button type="button" x-ref="closeBtn" @click="open = false" class="uj-guide-close">&times;</button>
            </div>

            {{-- body --}}
            <div class="uj-guide-body">
                <p class="uj-guide-text" x-text="c.body"></p>

                <template x-if="c.steps && c.steps.length">
                    <ol class="uj-guide-steps">
                        <template x-for="(step, i) in c.steps" :key="i">
                            <li class="uj-guide-step">
                                <span class="uj-guide-num" x-text="i + 1"></span>
                                <span x-text="step"></span>
                            </li>
                        </template>
                    </ol>
                </template>
            </div>

            {{-- footer: language toggle + close --}}
            <div class="uj-guide-foot">
                <div class="uj-seg">
                    <button type="button" @click="$store.ui.setLang('en')" :data-on="$store.ui.lang==='en'">EN</button>
                    <button type="button" @click="$store.ui.setLang('ms')" :data-on="$store.ui.lang==='ms'">BM</button>
                </div>
                <button type="button" @click="open = false" class="uj-btn-primary uj-guide-confirm"
                        x-text="$store.ui.lang==='en' ? 'Got it' : 'Faham'"></button>
            </div>
        </div>
    </div>
    </template>
</div>
