@php
    /** Reuses CalendarController::screenData, so the grid here and the Calendar
        screen can never drift apart. Month navigation is deliberately absent for
        now — it needs a partial swap, which is a later phase. */
    $today = now();
@endphp
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
            @php
                $marks = collect()
                    ->concat($day['holiday']->map(fn ($h) => ['holiday', $h->name]))
                    ->concat($day['leave']->map(fn ($l) => ['leave', $l->employee?->display_name ?? 'On leave']))
                    ->concat($day['events']->map(fn ($e) => ['event', $e->title]));
            @endphp
            <div class="uj-dw-cal-day" @if (! $day['inMonth']) data-out @endif @if ($day['isToday']) data-today @endif>
                <span class="n">{{ $day['date']->format('j') }}</span>
                <span class="uj-dw-cal-marks">
                    @foreach ($marks->take(2) as [$kind, $label])
                        <span class="uj-dw-cal-pill" data-k="{{ $kind }}" title="{{ $label }}">{{ $label }}</span>
                    @endforeach
                    @if ($marks->count() > 2)
                        <span class="uj-dw-cal-more">+{{ $marks->count() - 2 }}</span>
                    @endif
                </span>
            </div>
        @endforeach
    @endforeach
</div>
<div class="uj-dw-foot">
    <span>{{ ($w['outThisMonth'] ?? collect())->count() }}
        <span x-text="$store.ui.lang==='en' ? 'away this month' : 'bercuti bulan ini'">away this month</span>
    </span>
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', 'calendar') }}"
       x-text="$store.ui.lang==='en' ? 'Open calendar' : 'Buka kalendar'">Open calendar</a>
</div>
