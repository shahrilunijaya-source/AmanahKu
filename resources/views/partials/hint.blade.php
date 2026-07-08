{{--
    Field hint — small bilingual helper line under a form input. Follows the
    global $store.ui.lang toggle so it flips with the guide banner.

    Usage (bilingual — preferred):
        @include('partials.hint', ['en' => 'Use the date it happened.', 'ms' => 'Guna tarikh ia berlaku.'])
    Usage (flat — legacy, English only; BM falls back to English):
        @include('partials.hint', ['text' => 'Use the date it happened.'])
    Optional: $tone = 'info' (default) | 'warn'
--}}
@php
    $en = $en ?? ($text ?? '');
    $ms = $ms ?? $en;
    $tone = $tone ?? 'info';
@endphp
<p x-data="{ en: {{ \Illuminate\Support\Js::from($en) }}, ms: {{ \Illuminate\Support\Js::from($ms) }} }"
   style="font-size:11.5px;color:{{ $tone === 'warn' ? 'var(--amber)' : 'var(--muted)' }};margin:-2px 0 8px;line-height:1.45;display:flex;gap:5px;align-items:flex-start;">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;opacity:.7;"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
    <span x-text="$store.ui.lang==='en' ? en : ms"></span>
</p>
