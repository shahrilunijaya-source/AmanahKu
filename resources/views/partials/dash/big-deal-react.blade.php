{{--
    CR-24: reactions on a Big Deal (dashboard banner and Wins card share this
    region). Real CR-30 picker (partials.reaction-picker, data-reaction-pick)
    plus a small custom tally — deliberately NOT partials.reaction-tally: that
    partial wraps its icon in a nested <span> before the count, and CR24Test's
    tally assertion (`data-reaction-count="..."[^>]*>[^<]*2`) cannot cross that
    nested tag, so the count here is the chip's own first text node instead.
    Fetch-and-swap, same pattern as partials/dash/birthday-wishes.blade.php.

    $dealId  int
    $counts  array<string, int>  reaction key => count
    $mine    list<string>  viewer's own reaction keys on this deal
--}}
@php
    $mineJson = json_encode($mine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $labels = \App\Models\Reaction::labels();
@endphp
<div class="uj-bd-react" id="uj-bd-react-{{ $dealId }}"
     x-data="{
        busy: false,
        async react(key) {
            if (this.busy) return;
            const root = this.$root;
            this.busy = true;
            try {
                const res = await fetch(@js(route('big-deals.react', $dealId)), {
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
    @include('partials.reaction-picker', ['onPick' => "react('KEY')", 'mine' => $mineJson])
    <span class="uj-react-tally">
        @foreach ($counts as $key => $n)
            @continue($n < 1)
            @php $d = $labels[$key] ?? ['label' => $key, 'icon' => $key, 'retired' => true]; @endphp
            <span class="uj-react-chip" data-reaction-count="{{ $key }}" title="{{ $d['label'] }}">{{ $n }} <span aria-hidden="true">{{ $d['icon'] }}</span> {{ $d['label'] }}</span>
        @endforeach
    </span>
</div>
