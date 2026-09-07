{{--
    Dashboard bands (CR-32): full width, above the grid, in a fixed order —
    moments, management, awards. A slot with nothing active renders nothing, so
    an ordinary day leaves the page exactly as it was. `plain` (Keep it plain)
    drops the tint and ornament; the words stay.

    $bands  ['moments' => Moment|null, 'moments_count' => int, 'management' => null, 'awards' => null]
    $plain  bool
--}}
@php
    $m = $bands['moments'] ?? null;
    $plain = $plain ?? false;
@endphp
@if ($m || ($bands['management'] ?? null) || ($bands['awards'] ?? null))
<div class="uj-db" data-plain="{{ $plain ? '' : null }}">
    @if ($m)
        <section class="uj-db-band uj-db-moment" data-kind="{{ $m['kind'] }}" aria-label="{{ $m['title']['en'] }}">
            <span class="uj-db-k" x-text="$store.ui.lang==='en' ? @js($m['kicker']['en']) : @js($m['kicker']['ms'])">{{ $m['kicker']['en'] }}</span>
            <span class="uj-db-t" x-text="$store.ui.lang==='en' ? @js($m['title']['en']) : @js($m['title']['ms'])">{{ $m['title']['en'] }}</span>
            <span class="uj-db-s" x-text="$store.ui.lang==='en' ? @js($m['sub']['en']) : @js($m['sub']['ms'])">{{ $m['sub']['en'] }}</span>
            @if (! $plain && $m['art'])
                <div class="uj-db-art" aria-hidden="true">
                    @for ($i = 0; $i < 8; $i++)
                        <i style="left:{{ 8 + ($i * 47) % 150 }}px;top:{{ 6 + ($i * 37) % 54 }}px;--r:{{ ($i * 53) % 90 - 45 }}deg;--d:{{ ($i * 70) % 400 }}ms"></i>
                    @endfor
                    @if ($m['art'] === 'stamp')<span class="uj-db-stamp">CUTI</span>@endif
                </div>
            @endif
            @if ($m['cta'])
                <a class="uj-db-cta" href="{{ $m['cta']['url'] }}" x-text="$store.ui.lang==='en' ? @js($m['cta']['label']['en']) : @js($m['cta']['label']['ms'])">{{ $m['cta']['label']['en'] }}</a>
            @endif
            @if (($bands['moments_count'] ?? 0) > 1)
                <span class="uj-db-more" x-text="$store.ui.lang==='en' ? '+' + {{ $bands['moments_count'] - 1 }} + ' more' : '+' + {{ $bands['moments_count'] - 1 }} + ' lagi'">+{{ $bands['moments_count'] - 1 }} more</span>
            @endif
        </section>
    @endif
    {{-- management (CR-17) and awards (CR-14) slots render here, in this order, once they exist. --}}
</div>
@endif
