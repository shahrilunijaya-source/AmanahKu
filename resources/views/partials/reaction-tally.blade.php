{{--
    CR-30: what an item has been given, one chip per reaction with a count. Every
    active reaction gets a chip so a live count can appear without a reload; a retired
    reaction (or a pre-CR-30 emoji) gets one only while something is counted under it,
    so old items keep reading.

    $counts  array key => count, as rendered by the server
    $live    bool, the enclosing Alpine scope has a `reactions` object to read from
--}}
@php
    $labels = \App\Models\Reaction::labels();
    $live = $live ?? false;
    $keys = array_values(array_unique([...\App\Models\Reaction::activeKeys(), ...array_keys($counts)]));
@endphp
<span class="uj-react-tally">
    @foreach ($keys as $k)
        @php $d = $labels[$k] ?? ['label' => $k, 'icon' => $k, 'retired' => true]; $n = (int) ($counts[$k] ?? 0); @endphp
        <span class="uj-react-chip" data-reaction-count="{{ $k }}" @if ($d['retired']) data-retired @endif
              title="{{ $d['label'] }}"
              @if ($live) x-show="(reactions[@js($k)] || 0) > 0" @if (! $n) style="display:none" @endif @elseif (! $n) hidden @endif>
            <span aria-hidden="true">{{ $d['icon'] }}</span> {{ $d['label'] }} <b @if ($live) x-text="reactions[@js($k)] || 0" @endif>{{ $n }}</b>
        </span>
    @endforeach
</span>
