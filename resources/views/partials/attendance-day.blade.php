@php
    $rin = $r->clock_in ? \Illuminate\Support\Str::of($r->clock_in)->limit(5, '') : '—';
    $rout = $r->clock_out ? \Illuminate\Support\Str::of($r->clock_out)->limit(5, '') : '—';
    $worked = $r->worked_minutes ? intdiv($r->worked_minutes, 60).'h'.($r->worked_minutes % 60 ? sprintf('%02dm', $r->worked_minutes % 60) : '') : null;

    $flags = $r->flags ?? [];
    $hasRedFlag = (is_array($flags) && array_intersect($flags, ['out_of_radius_in', 'out_of_radius_out', 'early_out']));
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

    if ($r->date->isToday() && !$r->clock_in) {
        $dayDescEn = 'Nothing recorded yet today. Clock in above and this day fills itself in with the punch time, where it landed against the geofence, and any remark attached.';
        $dayDescMs = 'Belum ada rekod hari ini. Clock in di atas dan hari ini akan dikemaskini dengan masa clock, lokasi geofence, dan sebarang catatan.';
    } elseif ($hasRedFlag) {
        $dayDescEn = 'Punches fell outside the expected location or clock-out was early, raising flags for manager review.';
        $dayDescMs = 'Clock in/out berada di luar lokasi atau balik awal, memerlukan semakan pengurus.';
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
    if ($hasRadiusFlag || $r->in_radius === false || $r->out_radius === false) {
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

    $notes = array_filter([$r->clock_in_justification, $r->clock_out_justification]);
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
            <span class="uj-at-status" data-tone="{{ $rowStatusTone }}" style="display:inline-block;margin-top:6px;"
                  x-text="$store.ui.lang==='en' ? @js($stLabel[$r->status] ?? $r->status) : @js($stLabelMs[$r->status] ?? $r->status)">{{ $stLabel[$r->status] ?? $r->status }}</span>
        </span>
        <span class="uj-at-rowend">
            @foreach (array_diff($r->flags ?? [], ['late']) as $f)
                @php $fl = $flagLabel[$f] ?? [$f, $f]; @endphp
                <span class="uj-at-status" data-tone="red"
                      x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
            @endforeach
            <span class="uj-at-status" data-tone="{{ $rowStatusTone }}"
                  x-text="$store.ui.lang==='en' ? @js($stLabel[$r->status] ?? $r->status) : @js($stLabelMs[$r->status] ?? $r->status)">{{ $stLabel[$r->status] ?? $r->status }}</span>
            <svg class="uj-at-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
        </span>
    </button>

    <div class="uj-at-panel" :data-open="open ? '' : null">
        <div>
            <div class="uj-at-panel-in">
                <p x-text="$store.ui.lang==='en' ? @js($dayDescEn) : @js($dayDescMs)">{{ $dayDescEn }}</p>

                @if ($notes)
                    @foreach ($notes as $note)
                        <div class="uj-at-quote">
                            <div class="lbl" x-text="$store.ui.lang==='en' ? 'Your note on the punch' : 'Catatan anda pada rekod'">Your note on the punch</div>
                            <div class="txt">{{ $note }}</div>
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
