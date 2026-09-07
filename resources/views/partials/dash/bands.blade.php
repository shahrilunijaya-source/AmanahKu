{{--
    Dashboard bands (CR-32): full width, above the grid, in a fixed order —
    moments, management, awards. A slot with nothing active renders nothing, so
    an ordinary day leaves the page exactly as it was. `plain` (Keep it plain)
    drops the tint and ornament; the words stay.

    Every active moment is in the page. The one for today opens (rotates by
    day); the "1 / N" pill steps through the rest — a click, never a timer.

    A birthday moment (CR-13) also carries `wishesHtml` (the pre-rendered
    partials.dash.birthday-wishes region), a once-per-day confetti burst, and
    a "×" dismiss that hides it for the rest of the day — both gated on
    localStorage `uj-bday-<Y-m-d>`, wrapped in try/catch since a private
    window or blocked storage must not break the band.

    $bands  ['moments' => list<Moment>, 'moments_start' => int, 'management' => null, 'awards' => null, 'upcoming' => list<array{name,date}>]
    $plain  bool
--}}
@php
    $moments = $bands['moments'] ?? [];
    $plain = $plain ?? false;
    $upcoming = $bands['upcoming'] ?? [];
    $todayKey = now()->toDateString();
@endphp
@if ($moments !== [] || ($bands['management'] ?? null) || ($bands['awards'] ?? null))
<div class="uj-db" data-plain="{{ $plain ? '' : null }}">
    @if ($moments !== [])
        <div class="uj-db-moments" x-data="{ i: {{ (int) ($bands['moments_start'] ?? 0) }}, n: {{ count($moments) }} }">
        @foreach ($moments as $idx => $m)
            @php $isBirthday = $m['kind'] === 'birthday'; @endphp
            <section class="uj-db-band uj-db-moment" data-kind="{{ $m['kind'] }}" aria-label="{{ $m['title']['en'] }}"
                     @if ($isBirthday)
                         x-data="{
                            dismissed: false,
                            confetti: false,
                            key: 'uj-bday-{{ $todayKey }}',
                            init() {
                                try {
                                    const seen = localStorage.getItem(this.key);
                                    if (seen === 'hide') { this.dismissed = true; return; }
                                    if (!seen) {
                                        this.confetti = {{ $plain ? 'false' : 'true' }} && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                                        localStorage.setItem(this.key, 'seen');
                                    }
                                } catch (e) {}
                            },
                            dismiss() {
                                this.dismissed = true;
                                try { localStorage.setItem(this.key, 'hide'); } catch (e) {}
                            },
                         }"
                         x-show="i === {{ $idx }} && !dismissed"
                     @else
                         x-show="i === {{ $idx }}"
                     @endif
                     @if ($idx !== (int) ($bands['moments_start'] ?? 0)) style="display:none" @endif>
                <span class="uj-db-k" x-text="$store.ui.lang==='en' ? @js($m['kicker']['en']) : @js($m['kicker']['ms'])">{{ $m['kicker']['en'] }}</span>
                @if (! $plain && $m['art'] === 'cake')<span class="uj-db-cake" aria-hidden="true">🎂</span>@endif
                @if ($isBirthday && isset($m['employee']))
                    <span class="uj-db-avatar" style="background:{{ $m['employee']['avatar_color'] ?? '#3a6ea5' }}">{{ $m['employee']['initials'] }}</span>
                @endif
                <span class="uj-db-t" x-text="$store.ui.lang==='en' ? @js($m['title']['en']) : @js($m['title']['ms'])">{{ $m['title']['en'] }}</span>
                @if ($isBirthday && ! empty($m['employee']['position']))
                    <span class="uj-db-role">{{ $m['employee']['position'] }}</span>
                @endif
                <span class="uj-db-s" x-text="$store.ui.lang==='en' ? @js($m['sub']['en']) : @js($m['sub']['ms'])">{{ $m['sub']['en'] }}</span>
                @if (! $plain && $m['art'])
                    <div class="uj-db-art" aria-hidden="true">
                        @for ($i = 0; $i < 8; $i++)
                            <i style="left:{{ 8 + ($i * 47) % 150 }}px;top:{{ 6 + ($i * 37) % 54 }}px;--r:{{ ($i * 53) % 90 - 45 }}deg;--d:{{ ($i * 70) % 400 }}ms"></i>
                        @endfor
                        @if ($m['art'] === 'stamp')<span class="uj-db-stamp">CUTI</span>@endif
                    </div>
                @endif
                @if ($isBirthday)
                    @if (! $plain)
                        <div class="uj-db-confetti" aria-hidden="true" x-show="confetti" x-cloak>
                            @for ($i = 0; $i < 24; $i++)
                                <i style="left:{{ ($i * 41) % 100 }}%;top:{{ ($i * 23) % 60 }}%;--r:{{ ($i * 37) % 180 - 90 }}deg;--d:{{ ($i * 35) % 500 }}ms"></i>
                            @endfor
                        </div>
                    @endif
                    <button type="button" class="uj-db-dismiss" @click="dismiss()"
                            :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'">×</button>
                @endif
                @if ($m['cta'])
                    <a class="uj-db-cta" href="{{ $m['cta']['url'] }}" x-text="$store.ui.lang==='en' ? @js($m['cta']['label']['en']) : @js($m['cta']['label']['ms'])">{{ $m['cta']['label']['en'] }}</a>
                @endif
                @if (count($moments) > 1)
                    <button type="button" class="uj-db-more" @click="i = (i + 1) % n"
                            :aria-label="$store.ui.lang==='en' ? 'Next moment' : 'Seterusnya'">{{ $idx + 1 }} / {{ count($moments) }} ›</button>
                @endif
                @if ($isBirthday && isset($m['wishesHtml']))
                    {!! $m['wishesHtml'] !!}
                @endif
            </section>
        @endforeach
        </div>
    @endif
    {{-- management (CR-17) and awards (CR-14) slots render here, in this order, once they exist. --}}
    @if ($upcoming !== [])
        <div class="uj-db-upcoming">
            <span x-text="$store.ui.lang==='en' ? 'Coming up:' : 'Akan datang:'">Coming up:</span>
            {{ collect($upcoming)->map(fn ($u) => $u['name'].' ('.\Carbon\CarbonImmutable::parse($u['date'])->format('D j M').')')->implode(', ') }}
        </div>
    @endif
</div>
@endif
