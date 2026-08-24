@php
    /**
     * An OPTIONAL rail card ($railCards item in dash.blade.php) — hideable and
     * reorderable in Customise mode. Never used for $queue/$secondary, which are
     * pinned (see partials.dash.queue-card).
     *
     * Expects:
     *   $card   ['id','title','rows' => array]
     *   $index  int, entrance stagger position
     *
     * Row shape assumption (NOT specified by the data contract, which only
     * defines the ROW ARRAY shape for the pinned queues): each rail row is a
     * flat associative array of
     *   ['title','sub'?,'meta'?,'initials'?,'color'?,'tag'?,'url'?]
     * rendered as a compact list item — avatar/initials if given, else a
     * mono tag badge, else nothing; title + optional sub on the left, meta
     * right-aligned. This mirrors the mock's miniList/newsList/flatList
     * renderers, which all share this same visual shape. A row carrying
     * `url` renders as a link (currently only the External TOT rows in the
     * Announcements card do); every other row stays inert, as before.
     */
    $rows = $card['rows'] ?? [];
@endphp
{{-- The entrance stagger lives on an inner wrapper, not here. `uj-dq-stag` uses
     `animation: … forwards`, and a filled animation outranks a transition in the
     cascade — on the same element it pinned opacity:1 and the Customise hide had
     nothing left to fade. Separate elements, no conflict. `--i` still inherits. --}}
<div class="uj-card uj-dq-rail-card" style="--i:{{ $index }};position:relative;"
     data-card="{{ $card['id'] }}" data-opt
     x-show="!isHidden('{{ $card['id'] }}')" x-cloak
     {{-- Opacity only, and deliberately so: a fade is the one thing that survives a
          `prefers-reduced-motion` audit unchanged (it aids comprehension; travel does
          not), so this needs no reduced-motion override. Base form rather than the
          per-stage `x-transition:leave...` variant — the per-stage modifiers silently
          no-op on leave in Alpine 3.15, so the card vanished instead of fading. --}}
     x-transition.opacity.duration.200ms
     :style="{ order: ord('{{ $card['id'] }}') }">
    {{-- The card fades over .16s when Customise opens (app.css); without a matching
         transition the tools pill lands instantly on a card still on its way in. --}}
    <span class="uj-dq-card-tools" x-show="edit" x-cloak x-transition.opacity.duration.160ms>
        <button type="button" @click="move('{{ $card['id'] }}', -1)" :disabled="isFirstVisible('{{ $card['id'] }}')" :aria-label="$store.ui.lang==='en' ? 'Move up' : 'Naik'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
        </button>
        <button type="button" @click="move('{{ $card['id'] }}', 1)" :disabled="isLastVisible('{{ $card['id'] }}')" :aria-label="$store.ui.lang==='en' ? 'Move down' : 'Turun'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
        </button>
        <button type="button" data-hide @click="hide('{{ $card['id'] }}')" :aria-label="$store.ui.lang==='en' ? 'Hide this card' : 'Sembunyi kad ini'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22M9.88 9.88a3 3 0 1 0 4.24 4.24"/></svg>
        </button>
    </span>
    <div class="uj-dq-stag">
    <h2 class="uj-card-title">{{ $card['title'] }}</h2>
    <div :data-dq-inert="edit">
        @forelse ($rows as $r)
            @php $tag = empty($r['url']) ? 'div' : 'a'; @endphp
            <{{ $tag }} class="uj-dq-mini" @if (! empty($r['url'])) href="{{ $r['url'] }}" style="text-decoration:none;color:inherit;cursor:pointer;" @endif>
                @if (! empty($r['initials']))
                    <span class="av" style="background:{{ $r['color'] ?? 'var(--info)' }}">{{ $r['initials'] }}</span>
                @elseif (! empty($r['tag']))
                    <span class="uj-dq-tag">{{ $r['tag'] }}</span>
                @endif
                <span style="min-width:0;">
                    <span class="t" style="display:block;">{{ $r['title'] ?? '' }}</span>
                    @if (! empty($r['sub']))
                        <span class="s" style="display:block;">{{ $r['sub'] }}</span>
                    @endif
                </span>
                @if (! empty($r['meta']))
                    <span class="r">{{ $r['meta'] }}</span>
                @endif
            </{{ $tag }}>
        @empty
            @include('partials.list-empty', [
                'en' => ['title' => 'Nothing here yet', 'body' => 'This card is empty for now.'],
                'ms' => ['title' => 'Tiada apa-apa lagi', 'body' => 'Kad ini kosong buat masa ini.'],
                'pad' => '10px 0',
                'center' => false,
            ])
        @endforelse
    </div>
    </div>
</div>
