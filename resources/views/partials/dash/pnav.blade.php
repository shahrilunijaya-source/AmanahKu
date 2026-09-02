@php
    /** Period arrows. Clicking one refetches this card alone (shiftPeriod in
        dashboard-widgets.js), so the rest of the page keeps its scroll, its open
        folds and whatever period the other cards are on.

        No forward arrow at the current period on a backward-looking card: the
        server sends `next` as null, and a dead-end arrow is better greyed out
        than removed, so the row does not jump width as you walk back. */
    $words = [
        'day' => ['back' => ['Previous day', 'Hari sebelum'], 'fwd' => ['Next day', 'Hari seterusnya'], 'now' => ['Today', 'Hari ini']],
        'month' => ['back' => ['Previous month', 'Bulan sebelum'], 'fwd' => ['Next month', 'Bulan seterusnya'], 'now' => ['This month', 'Bulan ini']],
        'year' => ['back' => ['Previous year', 'Tahun sebelum'], 'fwd' => ['Next year', 'Tahun seterusnya'], 'now' => ['This year', 'Tahun ini']],
    ][$p['unit']];
@endphp
<div class="uj-dw-spacer"></div>
<div class="uj-dw-pnav">
    @unless ($p['isNow'])
        <button type="button" class="now" @click="shiftPeriod($el, null)"
                x-data="{ en: @js($words['now'][0]), ms: @js($words['now'][1]) }"
                x-text="$store.ui.lang==='en' ? en : ms">{{ $words['now'][0] }}</button>
    @endunless
    <button type="button" @click="shiftPeriod($el, @js($p['prev']))"
            x-data="{ en: @js($words['back'][0]), ms: @js($words['back'][1]) }"
            :aria-label="$store.ui.lang==='en' ? en : ms" aria-label="{{ $words['back'][0] }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <span class="label">{{ $p['label'] }}</span>
    <button type="button" @if ($p['next']) @click="shiftPeriod($el, @js($p['next']))" @else disabled @endif
            x-data="{ en: @js($words['fwd'][0]), ms: @js($words['fwd'][1]) }"
            :aria-label="$store.ui.lang==='en' ? en : ms" aria-label="{{ $words['fwd'][0] }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
    </button>
</div>
