@php
    $rin = $r->clock_in ? \Illuminate\Support\Str::of($r->clock_in)->limit(5, '') : '—';
    $rout = $r->clock_out ? \Illuminate\Support\Str::of($r->clock_out)->limit(5, '') : '—';
    $worked = $r->worked_minutes ? intdiv($r->worked_minutes, 60).'h'.($r->worked_minutes % 60 ? sprintf('%02dm', $r->worked_minutes % 60) : '') : null;

    $flags = $r->flags ?? [];
    /** Flags a manager has to look at. The rest ('late', 'short_hours') read amber. */
    $redFlags = ['out_of_radius_in', 'out_of_radius_out', 'early_out', 'no_location'];
    $hasRedFlag = (is_array($flags) && array_intersect($flags, $redFlags));
    if ($hasRedFlag) {
        $rowTone = 'red';
    } elseif ($r->status === 'late') {
        $rowTone = 'amber';
    } elseif ($r->status === 'on_time') {
        $rowTone = 'success';
    } else {
        $rowTone = 'muted';
    }

    $rowStatusTone = $stTone[$r->status] ?? 'muted';

    /**
     * One pill on the row, never a shelf of them. A day with four things wrong used to
     * print four red pills plus the status, which is five things to read before you learn
     * anything you can act on. Name the single problem when there is one, count them when
     * there are more, and let the row expand for the list. The count covers every flag,
     * 'late' included: this pill replaces the status pill rather than sitting beside it,
     * so a number that skipped 'late' would not match the list the row opens onto.
     *
     * @var array{0: string, 1: string, 2: string} $rowPill [English, Malay, tone]
     */
    $flagList = array_values($flags);
    $flagCount = count($flagList);
    $flagTone = $hasRedFlag ? 'red' : 'amber';
    if ($flagCount === 1) {
        $onlyFlag = $flagLabel[$flagList[0]] ?? [$flagList[0], $flagList[0]];
        $rowPill = [$onlyFlag[0], $onlyFlag[1], $flagTone];
    } elseif ($flagCount > 1) {
        $rowPill = ["{$flagCount} flags", "{$flagCount} tanda", $flagTone];
    } else {
        $rowPill = [$stLabel[$r->status] ?? $r->status, $stLabelMs[$r->status] ?? $r->status, $rowStatusTone];
    }

    if ($r->date->isToday() && !$r->clock_in) {
        $dayDescEn = 'Nothing recorded yet today. Clock in above and this day fills itself in with the punch time, where it landed against the geofence, and any remark attached.';
        $dayDescMs = 'Belum ada rekod hari ini. Clock in di atas dan hari ini akan dikemaskini dengan masa clock, lokasi geofence, dan sebarang catatan.';
    } elseif ($r->status === 'late') {
        $dayDescEn = 'Clocked in late for the shift. Any remark provided is attached to your attendance record for your manager.';
        $dayDescMs = 'Clock in lewat. Sebarang catatan dilampirkan pada rekod kehadiran untuk pengurus anda.';
    } elseif ($r->status === 'on_time') {
        $dayDescEn = 'A clean day. Both punches recorded on time and inside expected schedule.';
        $dayDescMs = 'Hari tanpa isu. Kedua-dua clock in/out tepat masa dan mengikut jadual.';
    } else {
        $dayDescEn = 'Attendance recorded for this day.';
        $dayDescMs = 'Kehadiran direkodkan untuk hari ini.';
    }

    // in_radius and out_radius are true when inside the geofence, false when outside, and null when no geofence was checked.
    $hasRadiusFlag = is_array($flags) && (in_array('out_of_radius_in', $flags) || in_array('out_of_radius_out', $flags));
    // A declared site visit still records in_radius === false when it lands outside the fence
    // (that part stays true), but the story is "went where they said they would", not a breach,
    // so this branch runs before the radius check below and takes it over.
    if ($r->work_mode === 'site_visit' || $r->clock_out_work_mode === 'site_visit') {
        // Keyed off which side actually is the site visit, not a blind fallback: an ordinary
        // late/off-site clock-in also fills clock_in_justification, and a `?:` there would
        // surface that remark instead of the clock-out destination on a mixed-mode day.
        $dest = $r->work_mode === 'site_visit' ? $r->clock_in_justification : $r->clock_out_justification;
        $whereEn = 'Site visit'.($dest ? ": {$dest}." : '.');
        $whereMs = 'Lawatan tapak'.($dest ? ": {$dest}." : '.');
    } elseif ($hasRadiusFlag || $r->in_radius === false || $r->out_radius === false) {
        $whereEn = 'Clock landed outside the expected geofence' . ($r->location ? " ({$r->location})." : '.');
        $whereMs = 'Clock berada di luar geofence yang ditetapkan' . ($r->location ? " ({$r->location})." : '.');
    } elseif ($r->in_radius === true && $r->out_radius === true) {
        $whereEn = 'Both punches landed inside the expected geofence' . ($r->location ? " ({$r->location})." : '.');
        $whereMs = 'Kedua-dua clock di dalam geofence yang ditetapkan' . ($r->location ? " ({$r->location})." : '.');
    } elseif ($r->in_radius === true && $r->out_radius === null) {
        $whereEn = 'Clock-in landed inside the expected geofence' . ($r->location ? " ({$r->location})." : '.');
        $whereMs = 'Clock-in di dalam geofence yang ditetapkan' . ($r->location ? " ({$r->location})." : '.');
    } elseif ($r->location) {
        $whereEn = "Recorded at {$r->location}.";
        $whereMs = "Direkodkan di {$r->location}.";
    } else {
        $whereEn = 'Location was not recorded.';
        $whereMs = 'Lokasi tidak direkodkan.';
    }

    /**
     * Both notes used to read "Your note on the punch", so a day with two of them gave the
     * same heading twice and never said which punch each one belonged to.
     *
     * @var list<array{0: string, 1: string, 2: string}> $notes [English label, Malay label, text]
     */
    $notes = [];
    if ($r->clock_in_justification) {
        $notes[] = ['Your note on the clock in', 'Catatan anda pada clock in', $r->clock_in_justification];
    }
    if ($r->clock_out_justification) {
        $notes[] = ['Your note on the clock out', 'Catatan anda pada clock out', $r->clock_out_justification];
    }
@endphp

<div class="uj-at-day" data-tone="{{ $rowTone }}" x-data="{ open: false }">
    <button type="button" class="uj-at-row" :aria-expanded="open ? 'true' : 'false'" @click="open = !open">
        <span class="uj-at-tile">
            <span class="m">{{ $r->date->format('M') }}</span>
            <span class="d">{{ $r->date->format('j') }}</span>
        </span>
        <span class="uj-at-rowmid">
            <span class="uj-at-rowt" style="display:block;">{{ $r->date->format('l') }}</span>
            <span class="uj-at-rows" style="display:block;">{{ $rin }}–{{ $rout }}@if ($worked) · {{ $worked }}@endif · {{ $r->location ?? ($site?->label ?? '—') }}</span>
            <span class="uj-at-status" data-tone="{{ $rowPill[2] }}"
                  x-text="$store.ui.lang==='en' ? @js($rowPill[0]) : @js($rowPill[1])">{{ $rowPill[0] }}</span>
        </span>
        <span class="uj-at-rowend">
            <span class="uj-at-status" data-tone="{{ $rowPill[2] }}"
                  x-text="$store.ui.lang==='en' ? @js($rowPill[0]) : @js($rowPill[1])">{{ $rowPill[0] }}</span>
            <svg class="uj-at-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
        </span>
    </button>

    <div class="uj-at-panel" :data-open="open ? '' : null">
        <div>
            <div class="uj-at-panel-in">
                {{-- Naming each flag replaces the generic sentence that used to sit here: it
                     said "punches fell outside the expected location OR clock-out was early"
                     without ever saying which, so the pills on the row were the only place the
                     answer lived. Now the row carries the count and this carries the list. --}}
                @if ($flags)
                    <ul class="uj-at-flags">
                        @foreach ($flags as $f)
                            @php $fl = $flagLabel[$f] ?? [$f, $f]; @endphp
                            <li data-tone="{{ in_array($f, $redFlags, true) ? 'red' : 'amber' }}"
                                x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</li>
                        @endforeach
                    </ul>
                @else
                    <p x-text="$store.ui.lang==='en' ? @js($dayDescEn) : @js($dayDescMs)">{{ $dayDescEn }}</p>
                @endif

                @if ($notes)
                    @foreach ($notes as $note)
                        <div class="uj-at-quote">
                            <div class="lbl" x-text="$store.ui.lang==='en' ? @js($note[0]) : @js($note[1])">{{ $note[0] }}</div>
                            <div class="txt">{{ $note[2] }}</div>
                        </div>
                    @endforeach
                @endif

                <div class="uj-at-evid">
                    @if ($r->photo_url)
                        <div class="uj-at-shot">
                            <img src="{{ $r->photo_url }}" alt="Clock-in selfie" loading="lazy" />
                        </div>
                    @endif
                    <div class="uj-at-where-line">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span x-text="$store.ui.lang==='en' ? @js($whereEn) : @js($whereMs)">{{ $whereEn }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
