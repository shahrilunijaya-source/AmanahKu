{{--
    Dashboard bands (CR-32): full width, above the grid, in a fixed order —
    moments, management, awards. A slot with nothing active renders nothing, so
    an ordinary day leaves the page exactly as it was. `plain` (Keep it plain)
    drops the tint and ornament; the words stay.

    Every active moment is in the page. The one for today opens (rotates by
    day); the "1 / N" pill steps through the rest — a click, never a timer.

    $bands  ['moments' => list<Moment>, 'moments_start' => int, 'management' => null, 'awards' => null]
    $plain  bool
--}}
@php
    $moments = $bands['moments'] ?? [];
    $plain = $plain ?? false;
@endphp
@if ($moments !== [] || ($bands['management'] ?? null) || ($bands['awards'] ?? null))
<div class="uj-db" data-plain="{{ $plain ? '' : null }}">
    @if ($moments !== [])
        <div class="uj-db-moments" x-data="{ i: {{ (int) ($bands['moments_start'] ?? 0) }}, n: {{ count($moments) }} }">
        @foreach ($moments as $idx => $m)
            <section class="uj-db-band uj-db-moment" data-kind="{{ $m['kind'] }}" aria-label="{{ $m['title']['en'] }}"
                     x-show="i === {{ $idx }}" @if ($idx !== (int) ($bands['moments_start'] ?? 0)) style="display:none" @endif>
                <span class="uj-db-k" x-text="$store.ui.lang==='en' ? @js($m['kicker']['en']) : @js($m['kicker']['ms'])">{{ $m['kicker']['en'] }}</span>
                @if (! $plain && $m['art'] === 'cake')<span class="uj-db-cake" aria-hidden="true">🎂</span>@endif
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
                @if (count($moments) > 1)
                    <button type="button" class="uj-db-more" @click="i = (i + 1) % n"
                            :aria-label="$store.ui.lang==='en' ? 'Next moment' : 'Seterusnya'">{{ $idx + 1 }} / {{ count($moments) }} ›</button>
                @endif
            </section>
        @endforeach
        </div>
    @endif
    {{-- management (CR-17) and awards (CR-14) slots render here, in this order, once they exist. --}}
</div>
@endif
