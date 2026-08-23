{{-- ── Mobile bottom dock (≤900px) ──────────────────────────────────────────
     Replaces the header hamburger as the way into navigation on a phone: the
     four screens a person actually opens sit on the thumb rail, and More opens
     a full-screen grid of every screen they can reach.

     The four tabs are the first four entries of $nav as this user sees it — that
     list is already role- and module-gated upstream (BuildsNav), so a hidden
     module never leaves a dead tab here and there is no second list to keep in
     step. The grid behind More is the same list in full.

     More does NOT open the off-canvas sidebar. The drawer is a 248px column of
     dark rows built for a desktop sidebar; on a phone the whole screen is free,
     and a grid of tiles gives every entry a thumb-sized target instead of a
     36px row. --}}
@php
    $dockItems = collect($nav)->take(4);
    // A group's own id is not always a screen, so a tile/tab for one links to its
    // first child — the same row the desktop panel would put first.
    $dockHref = function (array $item): string {
        $target = ! empty($item['children']) ? $item['children'][0] : $item;

        return route('app.screen', ['screen' => $target['id']] + ($target['query'] ?? []));
    };
    $dockOn = fn (array $item): bool => $item['active']
        || collect($item['children'] ?? [])->contains(fn ($c) => $c['active'] ?? false);
@endphp
<div x-data="{ more: false }" @keydown.escape.window="more = false">

    {{-- The grid. Sits above the page and below the dock, so the tab that opened it
         stays on screen to close it again. --}}
    <div class="uj-dockmore" x-show="more" x-cloak x-transition.opacity.duration.150ms
         @click.self="more = false">
        <div class="uj-dockmore-sheet">
            @foreach (collect($nav)->groupBy('section') as $section => $items)
                @php $sectionMs = $items->first()['section_ms'] ?? $section; @endphp
                <h2 class="uj-dockmore-sec" x-text="$store.ui.lang==='en' ? @js($section) : @js($sectionMs)">{{ $section }}</h2>
                <div class="uj-dockmore-grid">
                    @foreach ($items as $item)
                        <a href="{{ $dockHref($item) }}" class="uj-dockmore-tile" @click="more = false"
                           @if ($dockOn($item)) data-on @endif>
                            <span class="uj-dockmore-ico">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                            </span>
                            <span x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <nav class="uj-dock" style="backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);"
         :aria-label="$store.ui.lang==='en' ? 'Main' : 'Utama'">
        @foreach ($dockItems as $item)
            <a href="{{ $dockHref($item) }}" class="uj-dock-tab"
               @if ($dockOn($item)) data-on aria-current="page" @endif>
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"></path></svg>
                <span x-text="$store.ui.lang==='en' ? @js($item['label']) : @js($item['label_ms'] ?? $item['label'])">{{ $item['label'] }}</span>
            </a>
        @endforeach

        {{-- Opens and closes the grid: the tab a person just pressed is the one they
             reach for to get back, so it must be the way out too. --}}
        <button type="button" class="uj-dock-tab" @click="more = ! more" :data-on="more ? '' : null"
                :aria-expanded="more ? 'true' : 'false'">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
                <path x-show="! more" d="M4 7h16M4 12h16M4 17h16"></path>
                <path x-show="more" x-cloak d="M6 6l12 12M18 6L6 18"></path>
            </svg>
            <span x-text="$store.ui.lang==='en' ? (more ? 'Close' : 'More') : (more ? 'Tutup' : 'Lagi')">More</span>
        </button>
    </nav>
</div>
