@php
    /** Reuses CalendarController::screenData, so the grid here and the Calendar
        screen can never drift apart. Month navigation is deliberately absent for
        now — it needs a partial swap, which is a later phase.

        The tabs widen the circle rather than filter it: Personal is your own
        leave plus what applies to everyone, Team adds your reports, Company is
        everyone. Every entry is rendered once carrying the narrowest tab it
        belongs to, and the tab decides what shows — so switching tabs and picking
        a day are instant, with no trip to the server. */
    $today = now();
    $days = $w['days'] ?? [];
    $tabs = $w['calTabs'] ?? ['personal', 'company'];
    $tabLevels = ['personal' => 0, 'team' => 1, 'company' => 2];
    $tabLabels = ['personal' => ['Personal', 'Peribadi'], 'team' => ['Team', 'Pasukan'], 'company' => ['Company', 'Syarikat']];
    $kindLabels = ['leave' => ['Leave', 'Cuti'], 'pending' => ['Pending', 'Menunggu'], 'holiday' => ['Holiday', 'Cuti umum'], 'event' => ['Event', 'Acara']];
    // Day label and the per-tab entry count, so the panel heading can be written
    // client-side without shipping every day's heading as markup.
    $meta = collect($days)->map(fn (array $d): array => [
        'label' => $d['label'],
        'counts' => collect($d['marks'])->map(fn (array $m): int => $m['count'])->all(),
    ])->all();
@endphp
<div x-data="{
        tab: @js($tabs[0]),
        sel: @js($w['selected'] ?? $today->toDateString()),
        meta: @js($meta),
        level() { return ({ personal: 0, team: 1, company: 2 })[this.tab]; },
        count() { return this.meta[this.sel]?.counts[this.tab] ?? 0; },
    }">
    <div class="uj-dw-cal-today">
        <span>
            <span class="dow">{{ $today->format('l') }}</span>
            <span class="big">{{ $today->format('j F') }}</span>
        </span>
        <span class="badge">{{ $w['month'] ?? $today->format('F Y') }}</span>
        <a class="uj-dw-btn uj-dw-btn-red" style="margin-left:auto" href="{{ route('app.screen', 'leave') }}"
           x-text="$store.ui.lang==='en' ? 'New request' : 'Permohonan baru'">New request</a>
    </div>
    <div class="uj-dw-cal-grid">
        @foreach ($w['weekdays'] ?? [] as $d)
            <div class="uj-dw-cal-dow">{{ $d }}</div>
        @endforeach
    </div>
    <div class="uj-dw-cal-grid">
        @foreach ($w['weeks'] ?? [] as $week)
            @foreach ($week as $day)
                @php $key = $day['date']->toDateString(); @endphp
                @if (! $day['inMonth'])
                    <div class="uj-dw-cal-day" data-out><span class="n">{{ $day['date']->format('j') }}</span></div>
                @else
                    <button type="button" class="uj-dw-cal-day" @click="sel = @js($key)"
                            @if ($day['isToday']) data-today @endif
                            :data-sel="sel === @js($key) ? '' : null">
                        <span class="n">{{ $day['date']->format('j') }}</span>
                        @foreach ($tabs as $tab)
                            @php $mark = $days[$key]['marks'][$tab] ?? ['pills' => [], 'more' => 0]; @endphp
                            @continue (! $mark['pills'])
                            <span class="uj-dw-cal-marks" x-show="tab === @js($tab)" x-cloak>
                                @foreach ($mark['pills'] as $pill)
                                    <span class="uj-dw-cal-pill" data-k="{{ $pill['kind'] }}" title="{{ $pill['label'] }}">{{ $pill['label'] }}</span>
                                @endforeach
                                @if ($mark['more'])
                                    <span class="uj-dw-cal-more">+{{ $mark['more'] }}</span>
                                @endif
                            </span>
                        @endforeach
                    </button>
                @endif
            @endforeach
        @endforeach
    </div>

    {{-- Whose days you are looking at. Nobody reporting to you means no Team tab:
         it would say exactly what Personal already says. --}}
    <div class="uj-dw-cal-tabs">
        @foreach ($tabs as $tab)
            <button type="button" @click="tab = @js($tab)" :data-on="tab === @js($tab) ? '' : null"
                    x-data="{ en: @js($tabLabels[$tab][0]), ms: @js($tabLabels[$tab][1]) }"
                    x-text="$store.ui.lang==='en' ? en : ms">{{ $tabLabels[$tab][0] }}</button>
        @endforeach
    </div>
    <div class="uj-dw-cal-panel">
        <p class="uj-dw-cal-ph" x-text="(meta[sel]?.label ?? '') + (count()
            ? ' · ' + count() + ' ' + ($store.ui.lang==='en' ? (count() === 1 ? 'entry' : 'entries') : 'perkara')
            : '')"></p>
        @foreach ($days as $key => $day)
            @foreach ($day['entries'] as $entry)
                <div class="uj-dw-cal-item" x-cloak
                     x-show="sel === @js($key) && level() >= @js($entry['level'])">
                    <span class="av">{{ $entry['who'] }}</span>
                    <span class="txt">
                        <span class="t">{{ $entry['title'] }}</span>
                        <span class="s">{{ $entry['sub'] }}</span>
                    </span>
                    <span class="uj-dw-cal-tag" data-k="{{ $entry['kind'] }}"
                          x-data="{ en: @js($kindLabels[$entry['kind']][0]), ms: @js($kindLabels[$entry['kind']][1]) }"
                          x-text="$store.ui.lang==='en' ? en : ms">{{ $kindLabels[$entry['kind']][0] }}</span>
                </div>
            @endforeach
        @endforeach
        <p class="uj-dw-empty" x-show="! count()" x-cloak
           x-text="$store.ui.lang==='en' ? 'Nothing on this day.' : 'Tiada apa-apa pada hari ini.'">Nothing on this day.</p>
    </div>
</div>
<div class="uj-dw-foot">
    <span>{{ ($w['outThisMonth'] ?? collect())->count() }}
        <span x-text="$store.ui.lang==='en' ? 'away this month' : 'bercuti bulan ini'">away this month</span>
    </span>
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', 'calendar') }}"
       x-text="$store.ui.lang==='en' ? 'Open calendar' : 'Buka kalendar'">Open calendar</a>
</div>
