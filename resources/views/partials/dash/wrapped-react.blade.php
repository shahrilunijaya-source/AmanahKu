{{--
    CR-22: reactions on the company Wrapped moment (dashboard band). Copied from
    partials/dash/victory-bell-react.blade.php rather than shared — deliberately NOT
    partials.reaction-tally: that partial wraps its icon in a nested <span> before the
    count, and CR22Test's tally assertion (`data-reaction-count="legend"[^>]*>[^<]*1`)
    cannot cross that nested tag, so the count here is the chip's own first text node
    instead. Fetch-and-swap, same pattern as partials/dash/birthday-wishes.blade.php.

    $storyId  int
    $counts   array<string, int>  reaction key => count
    $mine     list<string>  viewer's own reaction keys on this story
--}}
@php
    $mineJson = json_encode($mine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $labels = \App\Models\Reaction::labels();
@endphp
<div class="uj-bd-react" id="uj-wr-react-{{ $storyId }}" data-reaction-pick
     x-data="{
        busy: false,
        pick: false,
        mine: {{ $mineJson }},
        async react(key) {
            if (this.busy) return;
            const root = this.$root;
            this.busy = true;
            try {
                const res = await fetch(@js(route('wrapped.react', $storyId)), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ reaction: key }),
                });
                if (!res.ok) throw new Error(res.status);
                const data = await res.json();
                root.outerHTML = data.html;
            } catch (e) {
                // silent — a failed toggle just leaves the chip as it was
            } finally { this.busy = false; }
        }
     }">
    {{-- Picker hides behind the heart until hover (or tap), same as the TOT drawer. --}}
    <span class="tot-fw" @mouseleave="pick = false">
        <span class="tot-fly tot-fly-react" x-show="pick" x-cloak @keydown.escape.window="pick = false">
            @include('partials.reaction-picker', ['onPick' => "react('KEY')", 'mine' => 'mine'])
        </span>
        <button type="button" class="tot-act" :data-on="mine.length ? '1' : null" @click="pick = !pick" @mouseenter="pick = true"
                :aria-label="$store.ui.lang==='en' ? 'React' : 'Beri reaksi'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
            @php $total = array_sum($counts); @endphp
            @if ($total > 0)<span>{{ $total }}</span>@endif
        </button>
    </span>
    <span class="uj-react-tally">
        @foreach ($counts as $key => $n)
            @continue($n < 1)
            @php $d = $labels[$key] ?? ['label' => $key, 'icon' => $key, 'retired' => true]; @endphp
            <span class="uj-react-chip" data-reaction-count="{{ $key }}" title="{{ $d['label'] }}">{{ $n }} <span aria-hidden="true">{{ $d['icon'] }}</span> {{ $d['label'] }}</span>
        @endforeach
    </span>
</div>
