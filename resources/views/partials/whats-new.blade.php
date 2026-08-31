{{--
    "What's new" — greets everyone once after a release, so the reload the update
    toast asked for lands on something that explains itself. Lists the entries
    marked `major: true` in changelog.yaml; everything else in the release is one
    click away on the Changelog screen.

    Remembered per browser in localStorage `amanahku-changelog-seen`, holding the
    version already read. A brand-new user never sees it — the welcome popup is
    already speaking to them, and to someone on their first day the whole app is new.
--}}
@php
    $latest = \App\Support\Changelog::releases()[0] ?? null;
    $major = array_values(array_filter($latest['entries'] ?? [], fn (array $e): bool => $e['major']));
    $rest = count($latest['entries'] ?? []) - count($major);
    /** The first line of an entry is its headline — the rest is detail for the Changelog screen. */
    $headline = fn (string $text): string => explode("\n", $text)[0];
    $tagLabel = [
        'added' => ['en' => 'Added', 'ms' => 'Ditambah'],
        'improved' => ['en' => 'Improved', 'ms' => 'Ditambah baik'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki'],
    ];
    $tagTone = ['added' => 'success', 'fixed' => 'error'];
@endphp
@if ($latest && $major)
<div x-data="{ show: false, version: @js($latest['version']) }"
     x-init="if (! localStorage.getItem('amanahku-welcomed')) {
                 localStorage.setItem('amanahku-changelog-seen', version);
             } else if (localStorage.getItem('amanahku-changelog-seen') !== version) {
                 show = true;
             }"
     @keydown.escape.window="localStorage.setItem('amanahku-changelog-seen', version); show = false">
<template x-teleport="body">
<div x-show="show" x-cloak class="uj-modal-scrim">

    <div @click.outside="localStorage.setItem('amanahku-changelog-seen', version); show = false"
         style="width:100%;max-width:500px;margin:auto;max-height:calc(100vh - 40px);display:flex;flex-direction:column;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);">

        {{-- Header band --}}
        <div style="padding:24px 26px 18px;background:linear-gradient(135deg,var(--red),#b03a2e);color:#fff;flex-shrink:0;">
            <div style="width:40px;height:40px;border-radius:11px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;margin-bottom:13px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.3 4.9 5.2.7-3.8 3.7.9 5.2-4.6-2.5-4.6 2.5.9-5.2L4.5 8.6l5.2-.7z"></path></svg>
            </div>
            <h2 style="font-size:19px;font-weight:600;margin:0;letter-spacing:-0.3px;"
                x-text="($store.ui.lang==='en' ? 'What\'s new in ' : 'Apa yang baharu dalam ') + version"></h2>
            <p style="font-size:13px;margin:6px 0 0;opacity:.92;line-height:1.5;"
               x-text="$store.ui.lang==='en'
                   ? 'Amanahku just updated. Here are the big ones.'
                   : 'Amanahku baru dikemas kini. Ini yang besar-besar.'"></p>
        </div>

        {{-- The top three --}}
        <div style="padding:20px 26px 8px;display:flex;flex-direction:column;gap:15px;overflow-y:auto;">
            @foreach ($major as $entry)
                @php $tone = $tagTone[$entry['tag']] ?? null; @endphp
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <span class="uj-stamp"@if ($tone) data-tone="{{ $tone }}" @endif style="flex-shrink:0;margin-top:1px;"
                          x-text="$store.ui.lang==='en' ? @js($tagLabel[$entry['tag']]['en']) : @js($tagLabel[$entry['tag']]['ms'])">{{ $tagLabel[$entry['tag']]['en'] }}</span>
                    <div class="uj-whatsnew-line" style="min-width:0;font-size:13px;color:var(--body);line-height:1.55;"
                         x-text="$store.ui.lang==='en' ? @js($headline($entry['text'])) : @js($headline($entry['text_ms']))">{{ $headline($entry['text']) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Footer --}}
        <div style="padding:16px 26px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;flex-shrink:0;">
            <div class="uj-seg">
                <button @click="$store.ui.setLang('en')" :data-on="$store.ui.lang==='en'">EN</button>
                <button @click="$store.ui.setLang('ms')" :data-on="$store.ui.lang==='ms'">BM</button>
            </div>
            <a href="{{ route('app.screen', 'changelog') }}"
               @click="localStorage.setItem('amanahku-changelog-seen', version); show = false"
               style="margin-left:auto;font-size:13px;font-weight:600;color:var(--red);text-decoration:none;"
               x-text="$store.ui.lang==='en'
                   ? @js($rest ? "Read more ({$rest} more) →" : 'Read more →')
                   : @js($rest ? "Baca lagi ({$rest} lagi) →" : 'Baca lagi →')">Read more &rarr;</a>
            <button @click="localStorage.setItem('amanahku-changelog-seen', version); show = false"
                    class="uj-btn-primary" style="margin-left:auto;height:42px;padding:0 22px;font-size:13.5px;"
                    x-text="$store.ui.lang==='en' ? 'Got it' : 'Faham'"></button>
        </div>
    </div>
</div>
</template>
</div>
@endif
