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
    localStorage `uj-bday-<Y-m-d>-<employee id>`; dismissing steps the cycler on, wrapped in try/catch since a private
    window or blocked storage must not break the band.

    $bands  ['moments' => list<Moment>, 'moments_start' => int, 'management' => slot|null, 'awards' => slot|null, 'upcoming' => list<array{name,date}>]
            a slot is ['kicker' => {en,ms}, 'title' => {en,ms}, 'sub' => {en,ms}]
    $plain  bool
--}}
@php
    $moments = $bands['moments'] ?? [];
    $plain = $plain ?? false;
    $upcoming = $bands['upcoming'] ?? [];
    $todayKey = now()->toDateString();
@endphp
@if ($moments !== [] || ($bands['management'] ?? null) || ($bands['awards'] ?? null))
<div class="uj-db"@if ($plain) data-plain=""@endif>
    @if ($moments !== [])
        <div class="uj-db-moments" x-data="{ i: {{ (int) ($bands['moments_start'] ?? 0) }}, n: {{ count($moments) }} }">
        @foreach ($moments as $idx => $m)
            @php $isBirthday = $m['kind'] === 'birthday'; $isBigDeal = $m['kind'] === 'big-deal'; $isVictoryBell = $m['kind'] === 'victory-bell'; $isWrapped = $m['kind'] === 'wrapped'; @endphp
            <section class="uj-db-band uj-db-moment" data-kind="{{ $m['kind'] }}" @if ($isBigDeal) data-big-deal="{{ $m['big_deal_id'] }}" @endif @if ($isVictoryBell) data-victory-bell="{{ $m['victory_bell_id'] }}" @endif @if ($isWrapped) data-wrapped-company="{{ $m['wrapped_story_id'] }}" @endif aria-label="{{ $m['title']['en'] }}"
                     @if ($isBirthday)
                         x-data="{
                            dismissed: false,
                            confetti: false,
                            key: 'uj-bday-{{ $todayKey }}-{{ $m['employee']['id'] ?? $idx }}',
                            init() {
                                try {
                                    const seen = localStorage.getItem(this.key);
                                    if (seen === 'hide') { this.dismissed = true; return; }
                                    if (!seen) {
                                        this.confetti = {{ $plain ? 'false' : 'true' }} && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                                        localStorage.setItem(this.key, 'seen');
                                        setTimeout(() => { this.confetti = false; }, 2200);
                                    }
                                } catch (e) {}
                            },
                            dismiss() {
                                this.dismissed = true;
                                this.i = (this.i + 1) % this.n;
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
                @if ($isVictoryBell && ! $plain)<span class="uj-vb-bell" aria-hidden="true">🔔</span>@endif
                @if ($isWrapped && ! $plain)<span class="uj-wr-num" aria-hidden="true">{{ $m['stats']['cards_closed'] }}</span>@endif
                @if ($isBirthday && isset($m['employee']))
                    <span class="uj-db-avatar" style="background:{{ $m['employee']['avatar_color'] ?? '#3a6ea5' }}">{{ $m['employee']['initials'] }}</span>
                @endif
                <span class="uj-db-t" x-text="$store.ui.lang==='en' ? @js($m['title']['en']) : @js($m['title']['ms'])">{{ $m['title']['en'] }}</span>
                @if ($isBirthday && ! empty($m['employee']['position']))
                    <span class="uj-db-role">{{ $m['employee']['position'] }}</span>
                @endif
                @if (($isBigDeal || $isVictoryBell) && $m['team'] !== [])
                    <span class="uj-bd-team">
                        @foreach ($m['team'] as $member)
                            <span class="uj-db-avatar" @if ($isVictoryBell) data-victory-bell-member="{{ $member['id'] }}" @else data-big-deal-member="{{ $member['id'] }}" @endif style="background:{{ $member['avatar_color'] ?? '#3a6ea5' }}">{{ $member['initials'] }}</span>
                        @endforeach
                        <small>{{ collect($m['team'])->pluck('display_name')->implode(', ') }}</small>
                    </span>
                @endif
                @if ($m['sub']['en'] !== '')
                    {{-- Wrapped's sentence carries literal "urgent" quote characters (CR22Test
                         asserts them raw, e.g. 'only 4 "urgent" tasks'); {{ }} would HTML-escape
                         them to &quot;, so its SSR fallback is unescaped, unlike every other kind. --}}
                    <span class="uj-db-s" x-text="$store.ui.lang==='en' ? @js($m['sub']['en']) : @js($m['sub']['ms'])">@if ($isWrapped){!! $m['sub']['en'] !!}@else{{ $m['sub']['en'] }}@endif</span>
                @endif
                @if ($isVictoryBell)
                    <span class="uj-vb-meta">{{ $m['meta'] }}</span>
                    {!! $m['reactHtml'] ?? '' !!}
                    @if (! $plain)
                        <div class="uj-db-confetti" aria-hidden="true">
                            @for ($i = 0; $i < 24; $i++)
                                <i style="left:{{ ($i * 41) % 100 }}%;top:{{ ($i * 23) % 60 }}%;--r:{{ ($i * 37) % 180 - 90 }}deg;--d:{{ ($i * 35) % 500 }}ms"></i>
                            @endfor
                        </div>
                    @endif
                @endif
                @if ($isWrapped)
                    {{-- Hidden, number-only carriers for CR22Test's stat() helper — separate
                         from the flat sentence above on purpose, see DashboardBands::wrappedMoment(). --}}
                    <span data-wrapped-stat="cards_closed" hidden>{{ $m['stats']['cards_closed'] }}</span>
                    <span data-wrapped-stat="lessons_shared" hidden>{{ $m['stats']['lessons_shared'] }}</span>
                    <span data-wrapped-stat="fires" hidden>{{ $m['stats']['fires'] }}</span>
                    <span data-wrapped-stat="urgent" hidden>{{ $m['stats']['urgent'] }}</span>
                    <span class="uj-wr-foot" x-text="$store.ui.lang==='en' ? 'Company totals, frozen with the awards.' : 'Jumlah syarikat, dibekukan bersama anugerah.'">Company totals, frozen with the awards.</span>
                    {!! $m['reactHtml'] ?? '' !!}
                @endif
                @if ($isBigDeal)
                    @if ($m['story_lines'] !== [] || $m['meta'])
                        <div class="uj-bd-story">
                            <b>What it took</b>
                            @foreach ($m['story_lines'] as $line)<p>{{ $line }}</p>@endforeach
                            <i>{{ $m['meta'] }}@if ($m['client_contact']) · {{ $m['client_contact'] }}@endif</i>
                        </div>
                    @endif
                    @if ($m['photos'] !== [])
                        <div class="uj-bd-photos">
                            @foreach ($m['photos'] as $photoId)
                                <img src="{{ route('big-deals.photos.show', [$m['big_deal_id'], $photoId]) }}" alt="" />
                            @endforeach
                        </div>
                    @endif
                    {!! $m['reactHtml'] ?? '' !!}
                @endif
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
    {{-- CR-32/CR-17: the management slot — lateness today + overdue by Primary Owner,
         text only, for FINAL_APPROVAL_ROLES every day. --}}
    @if ($bands['management'] ?? null)
        @php $mgmt = $bands['management']; @endphp
        <section class="uj-db-band uj-db-management" data-band="management" aria-label="{{ $mgmt['title']['en'] }}">
            <span class="uj-db-k" x-text="$store.ui.lang==='en' ? @js($mgmt['kicker']['en']) : @js($mgmt['kicker']['ms'])">{{ $mgmt['kicker']['en'] }}</span>
            <span class="uj-db-t" x-text="$store.ui.lang==='en' ? @js($mgmt['title']['en']) : @js($mgmt['title']['ms'])">{{ $mgmt['title']['en'] }}</span>
            <span class="uj-db-s" x-text="$store.ui.lang==='en' ? @js($mgmt['sub']['en']) : @js($mgmt['sub']['ms'])">{{ $mgmt['sub']['en'] }}</span>
            @include('partials.dash.management-panels', ['mgmt' => $mgmt])
        </section>
    @endif
    {{-- CR-32/CR-14b: the awards carousel — one data-slide per award (a tie shares its
         slide), manual awards first then App\Support\Awards::KEYS order. Auto-rotation,
         hover-pause and swipe are a human check (CR14bTest item 2); the markup and the
         reactions/comments underneath are exercised by the acceptance suite. --}}
    @if ($bands['awards'] ?? null)
        @php $b = $bands['awards']; $awardSlides = $b['slides']; @endphp
        <section class="uj-db-band uj-db-awards" data-band="awards" aria-label="{{ $b['title']['en'] }}"
                 x-data="{ i: 0, n: {{ $awardSlides->count() }}, timer: null,
                    start() { if ({{ $plain ? 'true' : 'false' }} || this.n < 2) return; this.timer = setInterval(() => { this.i = (this.i + 1) % this.n; }, 6000); },
                    stop() { clearInterval(this.timer); },
                    go(d) { this.stop(); this.i = (this.i + d + this.n) % this.n; },
                    tx: null,
                    swipeStart(e) { this.tx = e.changedTouches[0].clientX; },
                    swipeEnd(e) { if (this.tx === null) return; const dx = e.changedTouches[0].clientX - this.tx; this.tx = null; if (Math.abs(dx) > 40) this.go(dx < 0 ? 1 : -1); } }"
                 x-init="start()" @mouseenter="stop()" @mouseleave="start()" @touchstart.passive="swipeStart($event)" @touchend="swipeEnd($event)">
            <span class="uj-db-k" x-text="$store.ui.lang==='en' ? @js($b['kicker']['en']) : @js($b['kicker']['ms'])">{{ $b['kicker']['en'] }}</span>
            <span class="uj-db-t" x-text="$store.ui.lang==='en' ? @js($b['title']['en']) : @js($b['title']['ms'])">{{ $b['title']['en'] }}</span>
            <span class="uj-db-s" x-text="$store.ui.lang==='en' ? @js($b['sub']['en']) : @js($b['sub']['ms'])">{{ $b['sub']['en'] }}</span>
            <div class="uj-db-awards-track">
                @forelse ($awardSlides as $idx => $group)
                    <div x-show="i === {{ $idx }}" @if ($idx !== 0) style="display:none" @endif>
                        @include($group->award_key === 'mystery' ? 'partials.awards.mystery' : 'partials.awards.result', ['group' => $group, 'attr' => 'slide'])
                    </div>
                @empty
                    <p class="uj-db-awards-empty" x-text="$store.ui.lang==='en' ? 'Not published yet, check back soon.' : 'Belum diterbitkan, sila semak semula tidak lama lagi.'">Not published yet, check back soon.</p>
                @endforelse
            </div>
            @if ($awardSlides->count() > 1)
                {{-- QA S18 F8: arrows and dots you can see; swipe is on the section. --}}
                <div class="uj-db-awards-dots" role="tablist" style="display:flex;align-items:center;gap:6px;margin-top:8px;">
                    <button type="button" data-carousel-prev aria-label="Previous award" @click="go(-1)" style="border:1px solid var(--hairline);background:transparent;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:13px;line-height:1;">&lsaquo;</button>
                    @foreach ($awardSlides as $idx => $group)
                        <button type="button" role="tab" :aria-selected="i === {{ $idx }}" @click="stop(); i = {{ $idx }}"
                                :style="{ background: i === {{ $idx }} ? 'var(--ink)' : 'var(--hairline)' }"
                                style="width:8px;height:8px;border-radius:50%;border:0;padding:0;cursor:pointer;" aria-label="{{ $group->award_key === 'mystery' ? 'Mystery Award' : $group->copy['en']['name'] }}"></button>
                    @endforeach
                    <button type="button" data-carousel-next aria-label="Next award" @click="go(1)" style="border:1px solid var(--hairline);background:transparent;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:13px;line-height:1;">&rsaquo;</button>
                </div>
            @endif
            <a class="uj-db-cta" href="{{ url('/app/awards') }}" x-text="$store.ui.lang==='en' ? 'View all' : 'Lihat semua'">View all</a>
        </section>
    @endif
    @if ($upcoming !== [])
        <div class="uj-db-upcoming">
            <span x-text="$store.ui.lang==='en' ? 'Coming up:' : 'Akan datang:'">Coming up:</span>
            {{ collect($upcoming)->map(fn ($u) => $u['name'].' ('.\Carbon\CarbonImmutable::parse($u['date'])->format('D j M').')')->implode(', ') }}
        </div>
    @endif
</div>
@endif
